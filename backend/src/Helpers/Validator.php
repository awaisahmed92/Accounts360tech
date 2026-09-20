<?php
declare(strict_types=1);

namespace App\Helpers;

class Validator
{
    private array $errors = [];

    public function required(string $field, mixed $value): static
    {
        if ($value === null || $value === '') {
            $this->errors[$field] = "$field is required.";
        }
        return $this;
    }

    public function email(string $field, mixed $value): static
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "$field must be a valid email address.";
        }
        return $this;
    }

    public function minLength(string $field, mixed $value, int $min): static
    {
        if ($value !== null && strlen((string)$value) < $min) {
            $this->errors[$field] = "$field must be at least $min characters.";
        }
        return $this;
    }

    public function maxLength(string $field, mixed $value, int $max): static
    {
        if ($value !== null && strlen((string)$value) > $max) {
            $this->errors[$field] = "$field must not exceed $max characters.";
        }
        return $this;
    }

    public function password(string $field, mixed $value): static
    {
        if ($value === null || $value === '') return $this;
        $v = (string)$value;
        if (strlen($v) < 8) {
            $this->errors[$field] = "Password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $v)) {
            $this->errors[$field] = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[0-9]/', $v)) {
            $this->errors[$field] = "Password must contain at least one number.";
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
