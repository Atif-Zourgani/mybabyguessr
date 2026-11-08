<?php

namespace App\Admin;

/**
 * Ajoute `display_title` (titre du jeu, ou « Baby Nom » par défaut) à des lignes SQL brutes.
 * Fait en PHP plutôt qu'avec CONCAT/COALESCE pour rester portable entre MySQL et SQLite.
 */
final class DisplayTitle
{
    /**
     * @param list<array<string, mixed>> $rows lignes contenant `title` et `owner_last_name`
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['display_title'] = $row['title'] ?? 'Baby ' . ($row['owner_last_name'] ?? 'Guessr');
        }

        return $rows;
    }
}
