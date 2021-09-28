<?php

namespace BeyondCode\Vouchers\Exceptions;

use Illuminate\Database\Eloquent\Model;

class VoucherSoldOut extends \Exception
{
    protected $message = 'The voucher is sold out.';

    protected $voucher;

    public static function create(Model $voucher)
    {
        return new static($voucher);
    }

    public function __construct(Model $voucher)
    {
        $this->voucher = $voucher;
    }
}
