<?php

namespace App\Domain\Heritage\Exceptions;

use LogicException;

class HeritageObjectInUse extends LogicException
{
    public function __construct()
    {
        parent::__construct('Objek warisan masih digunakan dan tidak dapat dihapus.');
    }
}
