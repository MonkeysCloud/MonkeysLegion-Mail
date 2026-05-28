<?php

declare(strict_types=1);

/**
 * MonkeysLegion Mail
 *
 * @package   MonkeysLegion\Mail
 * @license   MIT
 */

namespace MonkeysLegion\Mail;

/**
 * Marker interface for transports that support advanced metadata.
 *
 * Transports implementing this interface can extract and use additional
 * email metadata from Message objects, including:
 * - tags: String array for categorization/tracking
 * - metadata: Key-value pairs for custom data
 * - variables: Template variable substitutions
 * - replyTo: Reply-To email address
 *
 * Transports NOT implementing this interface will simply ignore these fields.
 *
 * @example
 * if ($transport instanceof SupportsAdvancedMetadata) {
 *     // Transport supports tags, metadata, variables, reply-to
 * }
 */
interface SupportsAdvancedMetadata
{
    // No methods required — this is a marker interface for type checking.
}
