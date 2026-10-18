<?php

declare(strict_types=1);

namespace Nowo\LegalKitBundle\Placeholder;

/**
 * Markers of the shipped generic seed (REQ-CC-011 / CC-012): the shared contact address plus four identity
 * placeholders per locale. A published page that still contains one is not ready for production.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BuiltinMarkers
{
    /** @var list<string> */
    public const array MARKERS = [
        'privacy@example.com',
        // English
        'Operator legal name',
        'Operator tax ID',
        'Registered address — replace',
        '[if applicable — replace]',
        // Spanish
        'Nombre legal del operador',
        'Identificación fiscal del operador',
        'Domicilio social — sustituir',
        '[si aplica — sustituir]',
        // German
        'Rechtlicher Name des Betreibers',
        'Steuer-ID des Betreibers',
        'Sitz / Anschrift — ersetzen',
        '[falls zutreffend — ersetzen]',
        // French (U+2019 in l’opérateur)
        'Nom légal de l’opérateur',
        'Identifiant fiscal de l’opérateur',
        'Siège social — à remplacer',
        '[le cas échéant — à remplacer]',
        // Portuguese
        'Nome legal do operador',
        'Identificação fiscal do operador',
        'Sede social — substituir',
        '[se aplicável — substituir]',
        // Italian
        'Nome legale dell’operatore',
        'Identificativo fiscale dell’operatore',
        'Sede legale — sostituire',
        '[se applicabile — sostituire]',
        // Dutch
        'Wettelijke naam van de exploitant',
        'fiscaal nummer van de exploitant',
        'statutaire zetel — vervangen',
        '[indien van toepassing — vervangen]',
    ];
}
