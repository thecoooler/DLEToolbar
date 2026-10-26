<?php

namespace iCooLER\DLEToolbar;

/** Real MySQL storage with isolated metrics and fault injection for plugin tests. */
final class ToolbarTestDatabase
{
    public int $query_num = 0;
    public array $query_list = [];
    public float $MySQL_time_taken = 0;
    public array $query_errors_list = [];
    public array $queries = [];
    public ?string $failQuery = null;
    /** Thrown instead of returning false when $failQuery matches. */
    public ?\Throwable $failWith = null;

    public function __construct(private \db $native, array $metrics = [])
    {
        foreach ($metrics as $key => $value) {
            $this->$key = $value;
        }
    }

    public function query(string $sql, bool $showError = true, bool $logQuery = true): \mysqli_result|bool
    {
        $this->queries[] = $sql;
        if ($this->failQuery !== null && str_starts_with($sql, $this->failQuery)) {
            $this->failQuery = null;
            if ($this->failWith !== null) {
                throw $this->failWith;
            }
            $this->query_errors_list[] = ['query' => $sql, 'error' => 'Injected database failure'];
            return false;
        }
        $result = $this->native->query($sql, $showError, $logQuery);
        $this->query_errors_list = $this->native->query_errors_list;
        return $result;
    }

    public function get_row($result = null): ?array
    {
        return $this->native->get_row($result);
    }

    public function free($result = null): void
    {
        $this->native->free($result);
    }

    public function safesql(string $value): string
    {
        return $this->native->safesql($value);
    }
}
