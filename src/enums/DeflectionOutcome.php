<?php

declare(strict_types=1);

namespace justinholtweb\csr\enums;

use Craft;

/**
 * What happened after CSR showed somebody an article instead of a text area.
 *
 * The whole point of the deflection panel is the number this enum makes countable: of the people
 * who started to raise a ticket, how many stopped because they found the answer. Without an
 * explicit **Deflected** outcome that number can only be inferred from an absence, and an absence
 * is indistinguishable from a visitor whose laptop battery died.
 */
enum DeflectionOutcome: string
{
    /** They are still on the form. Nothing has been decided yet. */
    case Pending = 'pending';

    /** They said the article answered it, and no ticket was raised. */
    case Deflected = 'deflected';

    /** They read the suggestions and raised a ticket anyway. */
    case Submitted = 'submitted';

    /** They left without doing either, and the session aged out. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => Craft::t('csr', 'In progress'),
            self::Deflected => Craft::t('csr', 'Deflected'),
            self::Submitted => Craft::t('csr', 'Ticket raised'),
            self::Abandoned => Craft::t('csr', 'Abandoned'),
        };
    }

    /** Counted in the deflection rate. Abandoned and pending sessions are in neither half. */
    public function isDecided(): bool
    {
        return $this === self::Deflected || $this === self::Submitted;
    }
}
