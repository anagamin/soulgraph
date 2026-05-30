<?php

namespace App\Domain\Shared\Enums;

enum RelationType: string
{
    case Causes = 'causes';
    case Triggers = 'triggers';
    case Reinforces = 'reinforces';
    case Suppresses = 'suppresses';
    case AssociatedWith = 'associated_with';
    case Contradicts = 'contradicts';
    case EvolvesInto = 'evolves_into';
    case Symbolizes = 'symbolizes';
    case PartOf = 'part_of';
    case Precedes = 'precedes';
    case Follows = 'follows';
    case During = 'during';
    case LocatedIn = 'located_in';
    case Involves = 'involves';
    case Expresses = 'expresses';
}
