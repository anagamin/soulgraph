<?php

namespace App\Domain\Interview\Enums;

enum InterviewSessionType: string
{
    case GeneralStory = 'general_story';
    case LifePeriod = 'life_period';
    case Relationship = 'relationship';
    case Fear = 'fear';
    case Identity = 'identity';
    case Spirituality = 'spirituality';
    case Childhood = 'childhood';
    case OpenExploration = 'open_exploration';
}
