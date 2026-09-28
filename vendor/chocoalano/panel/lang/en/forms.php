<?php

declare(strict_types=1);

/*
 * Validation messages the panel's own fields raise, rather than ones Laravel
 * already has. Only the builder needs one so far: a block type that is not on
 * the field's list is rejected server-side, and the message has to say so in
 * the same place the field is drawn.
 */

return [
    'builder' => [
        'unknown_block' => 'This block is not one this field offers.',
    ],
];
