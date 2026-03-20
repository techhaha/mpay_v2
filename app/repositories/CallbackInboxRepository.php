<?php

namespace app\repositories;

use app\common\base\BaseRepository;
use app\models\CallbackInbox;
use Illuminate\Database\QueryException;

/**
 * 回调幂等收件箱仓储
 */
class CallbackInboxRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new CallbackInbox());
    }

    public function findByEventKey(string $eventKey): ?CallbackInbox
    {
        return $this->model->newQuery()
            ->where('event_key', $eventKey)
            ->first();
    }

    /**
     * 尝试创建幂等事件，重复时返回 false。
     */
    public function createIfAbsent(array $data): bool
    {
        try {
            $this->model->newQuery()->create($data);
            return true;
        } catch (QueryException $e) {
            // 1062: duplicate entry
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }
}

