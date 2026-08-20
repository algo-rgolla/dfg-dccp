<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class UserUploadValidator
{
    private PDO $conn;
    private array $errors = [];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function validateRow(array $row, int $rowNum): ?array
    {
        $errors = [];
        $username = trim((string)($row['Username'] ?? ''));
        $email    = trim((string)($row['Email'] ?? ''));

        if ($username === '' || $email === '') {
            $errors[] = "Row $rowNum: Username and Email are required.";
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Row $rowNum: Invalid email format.";
        }

        // Check duplicates in DB
        if ($username !== '' && $this->exists("Username", $username)) {
            $errors[] = "Row $rowNum: Username '$username' already exists.";
        }
        if ($email !== '' && $this->exists("Email", $email)) {
            $errors[] = "Row $rowNum: Email '$email' already exists.";
        }

        // RoleID check
        $roleId = (int)($row['RoleID'] ?? 0);
        if ($roleId > 0 && !$this->roleExists($roleId)) {
            $errors[] = "Row $rowNum: RoleID $roleId does not exist.";
        }

        if (!empty($errors)) {
            $this->errors = array_merge($this->errors, $errors);
            return null;
        }

        // Normalised data ready for UserModel->create
        return [
            'Username'           => $username,
            'Email'              => $email,
            'FirstName'          => trim((string)($row['FirstName'] ?? '')),
            'LastName'           => trim((string)($row['LastName'] ?? '')),
            'DisplayName'        => trim((string)($row['DisplayName'] ?? '')),
            'Phone'              => trim((string)($row['Phone'] ?? '')),
            'Department'         => trim((string)($row['Department'] ?? '')),
            'JobTitle'           => trim((string)($row['JobTitle'] ?? '')),
            'Notes'              => trim((string)($row['Notes'] ?? '')),
            'IsActive'           => (int)($row['IsActive'] ?? 1),
            'ForcePasswordReset' => (int)($row['ForcePasswordReset'] ?? 1),
            'MustChangePassword' => (int)($row['MustChangePassword'] ?? 0),
            'Password'           => trim((string)($row['Password'] ?? '')),
        ];
    }

    private function exists(string $col, string $value): bool
    {
        $sql = "SELECT COUNT(*) FROM dbo.tblUsers WHERE $col = :val";
        $st = $this->conn->prepare($sql);
        $st->execute([':val' => $value]);
        return (int)$st->fetchColumn() > 0;
    }

    private function roleExists(int $roleId): bool
    {
        $sql = "SELECT COUNT(*) FROM dbo.tblRoles WHERE RoleID = :id";
        $st = $this->conn->prepare($sql);
        $st->execute([':id' => $roleId]);
        return (int)$st->fetchColumn() > 0;
    }
}
