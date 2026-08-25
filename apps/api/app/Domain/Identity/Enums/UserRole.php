<?php

namespace App\Domain\Identity\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Researcher = 'researcher';
    case Teacher = 'teacher';
    case Student = 'student';

    public function isInternal(): bool
    {
        return $this !== self::Student;
    }
}
