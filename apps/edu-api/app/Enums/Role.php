<?php

namespace App\Enums;

enum Role: string
{
    case SchoolAdmin = 'school_admin';
    case Principal = 'principal';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
}
