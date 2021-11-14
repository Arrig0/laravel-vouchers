<?php

namespace BeyondCode\Vouchers;

use BeyondCode\Vouchers\Events\VoucherRedeemed;
use BeyondCode\Vouchers\Exceptions\VoucherAlreadyRedeemed;
use BeyondCode\Vouchers\Exceptions\VoucherConditionFails;
use BeyondCode\Vouchers\Exceptions\VoucherExpired;
use BeyondCode\Vouchers\Exceptions\VoucherIsInvalid;
use BeyondCode\Vouchers\Exceptions\VoucherNotForThatUser;
use BeyondCode\Vouchers\Exceptions\VoucherNotStarted;
use BeyondCode\Vouchers\Exceptions\VoucherSoldOut;
use BeyondCode\Vouchers\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Vouchers
{
    /** @var VoucherGenerator */
    private $generator;
    /** @var \BeyondCode\Vouchers\Models\Voucher */
    private $voucherModel;

    public function __construct(VoucherGenerator $generator)
    {
        $this->generator = $generator;
        $this->voucherModel = app(config('vouchers.model', Voucher::class));
    }

    /**
     * Generate the specified amount of codes and return
     * an array with all the generated codes.
     *
     * @param int $amount
     * @return array
     */
    public function generate(int $amount = 1, $voucherModel = null): array
    {
        $codes = [];

        for ($i = 1; $i <= $amount; $i++) {
            $codes[] = $this->getUniqueVoucher($voucherModel);
        }

        return $codes;
    }

    /**
     * @param Model $model
     * @param int $amount
     * @param array $data
     * @param null $expires_at
     * @return array
     */
    public function create(Model $model = null, int $amount = 1, array $data = [], $expires_at = null, $quantity = null,
                           $type = 'total', $value = null, $user_id = null, $quantity_per_user = 1, $starts_at = null,
                           $conditions = null, $voucherModel = null)
    {
        $vouchers = [];

        $voucherModel = $voucherModel ?: $this->voucherModel;
        $voucherModel = app($voucherModel);

        foreach ($this->generate($amount, $voucherModel) as $voucherCode) {
            $vouchers[] = $voucherModel->create([
                'model_id' => $model ? $model->getKey() : null,
                'model_type' => $model ? $model->getMorphClass() : null,
                'code' => $voucherCode,
                'data' => $data,
                'starts_at' => $starts_at,
                'expires_at' => $expires_at,
                'quantity' => $quantity,
                'quantity_left' => $quantity,
                'type' => $type,
                'value' => $value,
                'user_id' => $user_id,
                'quantity_per_user' => $quantity_per_user,
                'conditions' => $conditions,
            ]);
        }

        return $vouchers;
    }

    /**
     * @param string $code
     * @return Model
     * @throws VoucherExpired
     * @throws VoucherIsInvalid
     */
    public function check(Model $voucher, $user = null, $additionalData = [])
    {
        if ($voucher->isNotStarted()) {
            throw VoucherNotStarted::create($voucher);
        }
        if ($voucher->isExpired()) {
            throw VoucherExpired::create($voucher);
        }
        if ($voucher->isSoldout()) {
            throw VoucherSoldOut::create($voucher);
        }
        //THROWS VoucherConditionFails exception
        $voucher->checkConditions($user,$additionalData);
//        $customConditionsErrors = $voucher->checkConditions($user);
//        if (count($customConditionsErrors) > 0) {
//            throw VoucherConditionFails::create($voucher,);
//        }

        return $voucher;
    }

    public function checkByCode(string $code, $user = null,$additionalData = [], $voucherModel = null)
    {
        $voucherModel = $voucherModel ?: $this->voucherModel;
        $voucherModel = app($voucherModel);

        $voucher = $voucherModel->whereCode($code)->first();

        if (is_null($voucher)) {
            throw VoucherIsInvalid::withCode($code);
        }

        return $this->check($voucher, $user,$additionalData );

    }

    public function checkForRedeemByCode($user, string $code,$additionalData = [], $voucherModel = null)
    {

        $voucherModel = $voucherModel ?: $this->voucherModel;
        $voucherModel = app($voucherModel);

        $voucher = $voucherModel->whereCode($code)->first();

        if (is_null($voucher)) {
            throw VoucherIsInvalid::withCode($code);
        }

        return $this->checkForRedeem($user, $voucher,$additionalData);

    }

    public function checkForRedeem($user, Model $voucher,$additionalData = []) {

        $voucher = $this->check($voucher,$user,$additionalData);

        $associatedUserId = $voucher->getAssociatedUserId();
        if ($associatedUserId && $user->id != $associatedUserId) {
            throw VoucherNotForThatUser::create($voucher, $associatedUserId);
        }

        $quantityPerUser = $voucher->getQuantityPerUser();
        if (!is_null($quantityPerUser) && $voucher->users()
                ->wherePivot('user_id', $user->id)->count() >= $quantityPerUser) {
            throw VoucherAlreadyRedeemed::create($voucher);
        }
        return $voucher;
    }

    /**
     * @return string
     */
    protected function getUniqueVoucher($voucherModel = null): string
    {
        $voucherModel = $voucherModel ?: $this->voucherModel;
        $voucherModel = app($voucherModel);
        $voucher = $this->generator->generateUnique();

        while ($voucherModel->whereCode($voucher)->count() > 0) {
            $voucher = $this->generator->generateUnique();
        }

        return $voucher;
    }


    protected function redeem($user, Model $voucher, $useTransaction = true, $additionalData = [], $voucherModel = null)
    {

        $voucherModel = $voucherModel ?: $this->voucherModel;
        $voucherModel = app($voucherModel);

        $redeemRelation = $voucherModel->getRedeemRelation();

        $voucher = $this->checkForRedeem($user,$voucher,$additionalData);
        $quantityPerUser = $voucher->getQuantityPerUser();

        if (!$voucher->hasLimitedQuantity() && is_null($quantityPerUser)) {
            $user->$redeemRelation()->attach($voucher, [
                'redeemed_at' => now()
            ]);
        } else {

            if ($useTransaction) {
                DB::beginTransaction();
                try {
                    $this->redeemWithQuantity($user, $voucher, $quantityPerUser, $redeemRelation);
                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }

                DB::commit();
            } else {
                $this->redeemWithQuantity($user, $voucher, $quantityPerUser, $redeemRelation);
            }
        }

        event(new VoucherRedeemed($user, $voucher));
        return $voucher;

    }

    protected function redeemWithQuantity($user, Model $voucher, $quantityPerUser, $redeemRelation)
    {

        if ($voucher->hasLimitedQuantity() && $voucher->isSoldOut()) {
            throw VoucherSoldOut::create($voucher);
        }
        if ($quantityPerUser && $voucher->users()
                ->wherePivot('user_id', $user->id)->count() >= $quantityPerUser) {
            throw VoucherAlreadyRedeemed::create($voucher);
        }

        $user->$redeemRelation()->attach($voucher, [
            'redeemed_at' => now()
        ]);
        if ($voucher->hasLimitedQuantity()) {
            $voucher->update(['quantity_left' => $voucher->quantity_left - 1]);
        }

        return $voucher;
    }

    public function redeemCode($user, string $code, $useTransaction = true,$additionalData = [], $voucherModel = null)
    {
        $voucher = $this->checkByCode($code,$user,$additionalData, $voucherModel);

        return $this->redeem($user, $voucher, $useTransaction, $additionalData, $voucherModel);
    }

    public function redeemVoucher($user, Model $voucher, $useTransaction = true,$additionalData = [], $voucherModel = null)
    {

        $this->check($voucher,$user, $additionalData);

        return $this->redeem($user, $voucher, $useTransaction, $additionalData, $voucherModel);

    }
}
