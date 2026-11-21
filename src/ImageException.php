<?php // src/ImageException.php
declare(strict_types=1);
namespace Kip;

/** An image that cannot be safely decoded or re-encoded. The message is for logs, never for users. */
final class ImageException extends \RuntimeException {}
