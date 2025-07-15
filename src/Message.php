<?php

declare(strict_types=1);

namespace MonkeysLegion\Mail;

class Message
{
    public const CONTENT_TYPE_TEXT = 'text/plain';
    public const CONTENT_TYPE_HTML = 'text/html';
    public const CONTENT_TYPE_MIXED = 'multipart/mixed';
    public const CONTENT_TYPE_ALTERNATIVE = 'multipart/alternative';

    private string $from = '';
    private string $messageId = '';
    private string $date = '';
    private ?string $dkimSignature = null;

    /**
     * Message constructor.
     *
     * @param string $to The recipient's email address.
     * @param string $subject The subject of the email.
     * @param string $content The content of the email.
     * @param string $contentType The content type of the email (default is text/plain).
     * @param array $attachments An array of file paths to attach to the email.
     */
    public function __construct(
        private string $to,
        private string $subject,
        private string $content = '',
        private string $contentType = self::CONTENT_TYPE_TEXT,
        private array $attachments = []
    ) {
        $this->messageId = $this->generateMessageId();
        $this->date = date('r'); // RFC 2822 format
    }

    public function getHeaders(): array
    {
        $headers = [
            'From' => $this->getFrom(),
            'To' => $this->getTo(),
            'Subject' => $this->getSubject(),
            'Date' => $this->getDate(),
            'Message-ID' => $this->getMessageId(),
            'Content-Type' => $this->getContentType() . '; charset=UTF-8',
            'MIME-Version' => '1.0',
        ];

        if (!empty($this->dkimSignature)) {
            // Extract DKIM header from raw line
            if (stripos($this->dkimSignature, 'DKIM-Signature:') === 0) {
                [$name, $value] = explode(':', $this->dkimSignature, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return $headers;
    }

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

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function setFrom(string $from): void
    {
        $this->from = $from;
    }

    public function getDkimSignature(): ?string
    {
        return $this->dkimSignature;
    }

    public function setDkimSignature(string $dkimSignature): void
    {
        $this->dkimSignature = $dkimSignature;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    private function generateMessageId(): string
    {
        return '<' . uniqid() . '.' . time() . '@' . (gethostname() ?: 'localhost') . '>';
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
            && $this->attachments === $other->getAttachments();
    }

    public function toString(): string
    {
        $headers = [];
        foreach ($this->getHeaders() as $key => $value) {
            if (!empty($value)) {
                $headers[] = "$key: $value";
            }
        }

        $body = $this->content;

        if (!empty($this->attachments)) {
            foreach ($this->attachments as $attachment) {
                $body .= "\nAttachment: {$attachment}";
            }
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
