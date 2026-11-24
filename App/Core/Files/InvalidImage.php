<?php
declare(strict_types=1);

namespace TinyCat\Core\Files;

/** Rejected image input, distinct from storage and image processing failures. */
final class InvalidImage extends \RuntimeException {}
