<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

/** Best-effort masking, not a guarantee that arbitrary free text contains no secrets. */
final class Redactor
{
    private const SECRET = 'password|passwd|passphrase|pwd|token|access[_-]?token|refresh[_-]?token|id[_-]?token|api[_-]?key|secret|client[_-]?secret|private[_-]?key|authorization|proxy[_-]?authorization|cookie|set[_-]?cookie|credential[s]?';

    public function text(string $value): string
    {
        // Whole header lines, bearer credentials, known Cajeer credentials and common key=value forms.
        $patterns = [
            '~(?:^|(?<=[\r\n]))(\s*(?:authorization|proxy-authorization|cookie|set-cookie)\s*:)\s*[^\r\n]*~i' => '$1 [redacted]',
            '~\b(Bearer|Basic)\s+[A-Za-z0-9._\~+/-]+={0,2}~i' => '$1 [redacted]',
            '~\bcl_[a-f0-9]{64}\b~i' => '[redacted]',
            '~\b(https?://)[^\s/@:]+:[^\s/@]+@~i' => '$1[redacted]@',
            '~(?<![\w-])(["\']?(?:'.self::SECRET.')["\']?\s*[:=]\s*)(?:\[redacted\]|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,;&}\]]+)~i' => '$1[redacted]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $value);
            if ($masked === null) { throw new \RuntimeException('Secret masking failed.'); }
            $value = $masked;
        }
        return $value;
    }

    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $payload[$key] = $this->sensitive((string)$key) ? '[redacted]' : $this->value($value);
        }
        return $payload;
    }

    public function value(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach ($value as $key => $item) { $out->{$key} = $this->sensitive($key) ? '[redacted]' : $this->value($item); }
            return $out;
        }
        if (is_array($value)) { return $this->redact($value); }
        return is_string($value) ? $this->text($value) : $value;
    }

    private function sensitive(string $key): bool
    {
        $key=preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        return (bool)preg_match('~(?:^|[_-])(?:'.self::SECRET.')(?:$|[_-])~i', $key)
            || (bool)preg_match('~^(?:'.self::SECRET.')$~i', preg_replace('~[^a-z0-9]~i','',$key) ?? $key);
    }
}
