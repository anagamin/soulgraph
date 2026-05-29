<?php

namespace App\Domain\Shared\Enums;

enum EntityType: string
{
    case Person = 'person';
    case Place = 'place';
    case Event = 'event';
    case Epoch = 'epoch';
    case Emotion = 'emotion';
    case Interpretation = 'interpretation';
    case Motivation = 'motivation';
    case Fear = 'fear';
    case Identity = 'identity';
    case Pattern = 'pattern';
    case Belief = 'belief';
    case Value = 'value';
    case Goal = 'goal';
    case Practice = 'practice';
    case Relationship = 'relationship';
}
