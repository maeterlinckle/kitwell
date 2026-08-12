<?php

declare(strict_types=1);

namespace App\Api;

use Closure;

/**
 * One API resource, declared rather than coded.
 *
 * The same idea as `App\Reports\Report`: everything an endpoint needs to
 * describe and defend itself lives in one declaration, and a single generic
 * controller serves all of them. Adding a resource is one entry in
 * `ResourceRegistry` — routes, pagination, filtering, sorting, validation
 * shapes, error handling and the OpenAPI document all follow from it, with no
 * new controller and no new route.
 *
 * That is not tidiness for its own sake. Six hand-written controllers is six
 * chances to forget a permission check, six pagination conventions, and six
 * error shapes; this way the check is written once and every resource gets it.
 */
final class Resource
{
    /**
     * @param string                      $name       Path segment, e.g. 'assets'
     * @param array<string,string|null>   $permissions list/read/create/update/delete => permission slug, null = not offered
     * @param array<string,array<string,mixed>> $fields  Field name => ['type','description','writable','required','enum','format']
     * @param array<string,array<string,mixed>> $filters Query parameter => ['description','type','enum','model_key']
     * @param array<string,string>        $sorts      API sort name => the model's own sort key
     * @param Closure                     $list       fn(array $filters): array<int,array>
     * @param Closure|null                $get        fn(int $id): ?array
     * @param Closure|null                $create     fn(array $input): int
     * @param Closure|null                $update     fn(int $id, array $input, array $existing): void
     * @param Closure|null                $delete     fn(int $id, array $existing): void
     * @param Closure|null                $validate   fn(array $input, ?array $existing): array — returns field errors
     */
    public function __construct(
        public readonly string $name,
        public readonly string $singular,
        public readonly string $description,
        public readonly array $permissions,
        public readonly array $fields,
        public readonly array $filters,
        public readonly array $sorts,
        private readonly Closure $list,
        private readonly ?Closure $get = null,
        private readonly ?Closure $create = null,
        private readonly ?Closure $update = null,
        private readonly ?Closure $delete = null,
        private readonly ?Closure $validate = null,
        public readonly string $defaultSort = '',
    ) {
    }

    public function supports(string $action): bool
    {
        return match ($action) {
            'list'   => true,
            'read'   => $this->get !== null,
            'create' => $this->create !== null && ($this->permissions['create'] ?? null) !== null,
            'update' => $this->update !== null && ($this->permissions['update'] ?? null) !== null,
            'delete' => $this->delete !== null && ($this->permissions['delete'] ?? null) !== null,
            default  => false,
        };
    }

    public function permission(string $action): ?string
    {
        return $this->permissions[$action] ?? null;
    }

    /** HTTP methods this resource answers on its collection and item paths. */
    public function allowedMethods(bool $item): array
    {
        if ($item) {
            $methods = ['GET'];
            if ($this->supports('update')) {
                $methods[] = 'PATCH';
                $methods[] = 'PUT';
            }
            if ($this->supports('delete')) {
                $methods[] = 'DELETE';
            }

            return $methods;
        }

        return $this->supports('create') ? ['GET', 'POST'] : ['GET'];
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchList(array $filters): array
    {
        return ($this->list)($filters);
    }

    /** @return array<string,mixed>|null */
    public function fetchOne(int $id): ?array
    {
        return $this->get === null ? null : ($this->get)($id);
    }

    /** @param array<string,mixed> $input */
    public function insert(array $input): int
    {
        return ($this->create)($input);
    }

    /** @param array<string,mixed> $input */
    public function modify(int $id, array $input, array $existing): void
    {
        ($this->update)($id, $input, $existing);
    }

    /** @param array<string,mixed> $existing */
    public function remove(int $id, array $existing): void
    {
        ($this->delete)($id, $existing);
    }

    /**
     * Field errors for a write, or [] when it is acceptable.
     *
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing null on create
     * @return array<string,string>
     */
    public function errorsFor(array $input, ?array $existing): array
    {
        $errors = [];

        // Required-on-create is checked here rather than in each resource's own
        // validator, so no resource can forget it.
        if ($existing === null) {
            foreach ($this->fields as $name => $field) {
                if (($field['required'] ?? false) === true
                    && (!array_key_exists($name, $input) || $input[$name] === '' || $input[$name] === null)) {
                    $errors[$name] = ($field['label'] ?? $name) . ' is required.';
                }
            }
        }

        // So is the enum check: a declared set of values is a promise the
        // OpenAPI document makes to clients, and it has to be kept.
        foreach ($this->fields as $name => $field) {
            if (!array_key_exists($name, $input) || $input[$name] === null || $input[$name] === '') {
                continue;
            }

            if (isset($field['enum']) && !in_array((string) $input[$name], (array) $field['enum'], true)) {
                $errors[$name] = ($field['label'] ?? $name) . ' must be one of: ' . implode(', ', (array) $field['enum']) . '.';
            }
        }

        if ($this->validate !== null) {
            $errors = array_merge($errors, ($this->validate)($input, $existing));
        }

        return $errors;
    }

    /**
     * The writable subset of a submitted body.
     *
     * An allow-list, not a deny-list: a field the resource does not declare
     * writable never reaches the model, so no request can set `created_by`, an
     * id, or a column nobody thought about.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function writable(array $input): array
    {
        $clean = [];

        foreach ($this->fields as $name => $field) {
            if (($field['writable'] ?? false) !== true || !array_key_exists($name, $input)) {
                continue;
            }

            $clean[$name] = $input[$name];
        }

        return $clean;
    }

    /**
     * Unknown keys in a submitted body.
     *
     * Reported rather than ignored. Silently dropping `assetTag` because the
     * field is called `asset_tag` is how somebody spends an afternoon watching
     * a 200 response not change anything.
     *
     * @param array<string,mixed> $input
     * @return array<int,string>
     */
    public function unknownFields(array $input): array
    {
        return array_values(array_diff(array_keys($input), array_keys($this->fields)));
    }

    /**
     * Shape one row for output: declared fields only, in declaration order.
     *
     * The same allow-list in the other direction. A model's SELECT may carry
     * joined columns, internal ids or a password hash; only what this resource
     * declares is ever serialised.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function present(array $row): array
    {
        $out = [];

        foreach ($this->fields as $name => $field) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $out[$name] = self::cast($row[$name], (string) ($field['type'] ?? 'string'));
        }

        return $out;
    }

    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'number'  => (float) $value,
            'boolean' => (int) $value === 1,
            default   => (string) $value,
        };
    }
}
