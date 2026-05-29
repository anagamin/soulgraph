<?php

namespace App\Domain\Autobiography\Enums;

enum AutobiographyStyle: string
{
    case Neutral = 'neutral';
    case Literary = 'literary';
    case Philosophical = 'philosophical';
    case Psychological = 'psychological';
    case Documentary = 'documentary';
    case Spiritual = 'spiritual';
}
