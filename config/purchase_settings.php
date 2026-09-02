<?php

/**
 * How many KG in the unit your rate is quoted in ("40KG (per MUN)" on
 * your sheet — a maund). If you ever quote rates per a different
 * traditional maund (e.g. 37.32kg), change this one number — nothing
 * else in the codebase hardcodes it.
 */

return [
    'kg_per_maund' => env('KG_PER_MAUND', 40),
];
