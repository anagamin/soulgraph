<?php

namespace App\Domain\Interview\Enums;

enum InterviewMode: string
{
    case AiInterview = 'ai_interview';
    case Upload = 'upload';
}
