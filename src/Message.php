<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

class Message
{
    public const CONTENT_TYPE_TEXT = 'text/plain';
    public const CONTENT_TYPE_HTML = 'text/html';
    public const CONTENT_TYPE_MIXED = 'multipart/mixed';
    public const CONTENT_TYPE_ALTERNATIVE = 'multipart/alternative';

    /**
     * Message constructor.
     *
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $content The content of the email.
     * @param string $contentType The content type of the email (default is text/plain).
     * @param array $attachments An array of file paths to attach to the email.
     * @param array $inlineImages An array of inline images to include in the email.
     */
    public function __construct(
        private string $to,
        private string $subject,
        private string $content = '',
        private string $contentType = self::CONTENT_TYPE_TEXT,
        private array $attachments = [],
        private array $inlineImages = []
    ) {}

    public function getTo(): string
    {
        return $this->to;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getInlineImages(): array
    {
        return $this->inlineImages;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * Creates a new instance of Message with the specified recipient.
     * @param string $to The recipient's email address.
     * @return self A new instance of Message with the updated recipient.
     */
    public function withSubject(string $subject): self
    {
        $clone = clone $this;
        $clone->subject = $subject;
        return $clone;
    }

    /**
     * Creates a new instance of Message with the specified content.
     * @param string $content The content of the email.
     * @return self A new instance of Message with the updated content.
     */
    public function withContentType(string $contentType): self
    {
        $clone = clone $this;
        $clone->contentType = $contentType;
        return $clone;
    }

    /**
     * Compares this message with another message for equality.
     * @param Message $other The message to compare with.
     * @return bool True if the messages are equal, false otherwise.
     */
    public function equals(Message $other): bool
    {
        return $this->to === $other->getTo()
            && $this->subject === $other->getSubject()
            && $this->content === $other->getContent()
            && $this->contentType === $other->getContentType()
            && $this->attachments === $other->getAttachments()
            && $this->inlineImages === $other->getInlineImages();
    }

    public function toString(): string
    {
        $headers = [
            "To: {$this->getTo()}",
            "Subject: {$this->getSubject()}",
            "Content-Type: {$this->getContentType()}; charset=UTF-8",
            "MIME-Version: 1.0"
        ];

        $body = $this->content;

        if (!empty($this->attachments)) {
            foreach ($this->attachments as $attachment) {
                $body .= "\nAttachment: {$attachment}";
            }
        }

        if (!empty($this->inlineImages)) {
            foreach ($this->inlineImages as $image) {
                $body .= "\nInline Image: {$image}";
            }
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
