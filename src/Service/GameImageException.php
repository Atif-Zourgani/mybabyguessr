<?php

namespace App\Service;

/**
 * Photo de jeu refusée. `reason` correspond à la fin de la clé de traduction « games.new.error_image_<reason> ».
 */
final class GameImageException extends \RuntimeException
{
    public const UPLOAD     = 'upload';      // fichier mal reçu par le serveur
    public const SIZE       = 'size';        // plus de 4 Mo
    public const TYPE       = 'type';        // ni JPG, ni PNG, ni WebP
    public const DIMENSIONS = 'dimensions';  // trop de pixels pour être décodé en sécurité
    public const PROCESSING = 'processing';  // image illisible ou écriture impossible

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('Game image rejected: ' . $reason, 0, $previous);
    }
}
