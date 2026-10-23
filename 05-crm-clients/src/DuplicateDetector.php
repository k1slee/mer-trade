<?php

declare(strict_types=1);

final class DuplicateEdge
{
    public const LEVEL_EXACT = 'exact';
    public const LEVEL_MANUAL = 'manual';

    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public string $clientIdA,
        public string $clientIdB,
        public string $level,
        public array $reasons,
        public float $nameSimilarity,
    ) {
    }
}

final class SimilarPair
{
    public function __construct(
        public string $clientIdA,
        public string $clientIdB,
        public string $description,
    ) {
    }
}

final class DuplicateGroup
{
    /**
     * @param list<string> $clientIds
     * @param list<DuplicateEdge> $edges
     */
    public function __construct(
        public array $clientIds,
        public string $confidenceLabel,
        public array $edges,
        public bool $mergesViaWeakLink,
    ) {
    }
}

final class DuplicateDetectionResult
{
    /**
     * @param list<DuplicateGroup> $groups
     * @param list<SimilarPair> $similarNotDuplicates
     */
    public function __construct(
        public array $groups,
        public array $similarNotDuplicates,
    ) {
    }
}

final class DuplicateDetector
{
    /** Порог схожести названий для «точно» при совпадении УНП/телефона/email */
    public const NAME_SIMILARITY_EXACT_THRESHOLD = 70.0;

    /** Порог схожести названий без общих реквизитов → «проверить вручную» */
    public const NAME_SIMILARITY_MANUAL_ONLY_THRESHOLD = 85.0;

    /**
     * @param list<Client> $clients
     */
    public function detect(array $clients): DuplicateDetectionResult
    {
        $byId = [];
        foreach ($clients as $client) {
            $byId[$client->clientId] = $client;
        }
        $ids = array_keys($byId);
        sort($ids);

        $duplicateEdges = [];
        $similarPairs = [];

        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $byId[$ids[$i]];
                $b = $byId[$ids[$j]];
                $pairKey = self::pairKey($a->clientId, $b->clientId);

                $sim = Normalizer::nameSimilarityNorm($a->nameNorm, $b->nameNorm);
                $sameUnp = self::sameNorm($a->unpNorm, $b->unpNorm);
                $samePhone = self::sameNorm($a->phoneNorm, $b->phoneNorm);
                $sameEmail = self::sameNorm($a->emailNorm, $b->emailNorm);
                $differentUnp = !$sameUnp && $a->unpNorm !== null && $b->unpNorm !== null;
                $differentNames = $sim < self::NAME_SIMILARITY_EXACT_THRESHOLD;

                $edge = self::classifyDuplicateEdge($a, $b, $sim, $sameUnp, $samePhone, $sameEmail);
                if ($edge !== null) {
                    $duplicateEdges[$pairKey] = $edge;
                    continue;
                }

                $similar = self::classifySimilarNotDuplicate(
                    $a,
                    $b,
                    $sim,
                    $sameUnp,
                    $samePhone,
                    $sameEmail,
                    $differentUnp,
                    $differentNames,
                );
                if ($similar !== null) {
                    $similarPairs[$pairKey] = $similar;
                }
            }
        }

        $groups = self::buildGroups($duplicateEdges, $byId);

        usort($similarPairs, static function (SimilarPair $x, SimilarPair $y): int {
            $c = strcmp($x->clientIdA, $y->clientIdA);
            if ($c !== 0) {
                return $c;
            }

            return strcmp($x->clientIdB, $y->clientIdB);
        });

        return new DuplicateDetectionResult($groups, array_values($similarPairs));
    }

    private static function sameNorm(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && $a === $b;
    }

    private static function classifyDuplicateEdge(
        Client $a,
        Client $b,
        float $sim,
        bool $sameUnp,
        bool $samePhone,
        bool $sameEmail,
    ): ?DuplicateEdge {
        $isExact = ($sameUnp && $sim >= self::NAME_SIMILARITY_EXACT_THRESHOLD)
            || ($samePhone && ($sim >= self::NAME_SIMILARITY_EXACT_THRESHOLD || $sameUnp || $sameEmail))
            || ($sameEmail && $sim >= self::NAME_SIMILARITY_EXACT_THRESHOLD);

        if ($isExact) {
            $reasons = [];
            if ($sameUnp) {
                $reasons[] = sprintf('совпал УНП (%s)', $a->unpNorm);
            }
            if ($samePhone) {
                $reasons[] = sprintf('совпал телефон (%s)', $a->phoneNorm);
            }
            if ($sameEmail) {
                $reasons[] = sprintf('совпал email (%s)', $a->emailNorm);
            }
            $reasons[] = sprintf('схожесть названий %.0f%%', $sim);

            return new DuplicateEdge(
                $a->clientId,
                $b->clientId,
                DuplicateEdge::LEVEL_EXACT,
                [implode(', ', $reasons)],
                $sim,
            );
        }

        if ($sameUnp && $sim < self::NAME_SIMILARITY_EXACT_THRESHOLD) {
            return new DuplicateEdge(
                $a->clientId,
                $b->clientId,
                DuplicateEdge::LEVEL_MANUAL,
                [sprintf('тот же УНП (%s), но схожесть названий только %.0f%%', $a->unpNorm, $sim)],
                $sim,
            );
        }

        if ($samePhone && !$sameUnp && !$sameEmail && $sim < self::NAME_SIMILARITY_EXACT_THRESHOLD) {
            // Оба УНП известны и разные → вероятно, общий офис/секретарь, не дубль.
            if ($a->unpNorm !== null && $b->unpNorm !== null && $a->unpNorm !== $b->unpNorm) {
                return null;
            }
            return new DuplicateEdge(
                $a->clientId,
                $b->clientId,
                DuplicateEdge::LEVEL_MANUAL,
                [sprintf('тот же телефон (%s), но разные названия (%.0f%%) и нет других совпадений', $a->phoneNorm, $sim)],
                $sim,
            );
        }

        if ($sameEmail && !$sameUnp && $sim < self::NAME_SIMILARITY_EXACT_THRESHOLD) {
            // Оба УНП известны и разные → вероятно, общий ящик бухгалтерии, не дубль.
            if ($a->unpNorm !== null && $b->unpNorm !== null && $a->unpNorm !== $b->unpNorm) {
                return null;
            }
            return new DuplicateEdge(
                $a->clientId,
                $b->clientId,
                DuplicateEdge::LEVEL_MANUAL,
                [sprintf('тот же email (%s), но разные названия (%.0f%%) и нет других совпадений', $a->emailNorm, $sim)],
                $sim,
            );
        }

        if ($sim >= self::NAME_SIMILARITY_MANUAL_ONLY_THRESHOLD && !$sameUnp && !$samePhone && !$sameEmail) {
            return new DuplicateEdge(
                $a->clientId,
                $b->clientId,
                DuplicateEdge::LEVEL_MANUAL,
                [sprintf('схожесть названий %.0f%%, но нет общих реквизитов', $sim)],
                $sim,
            );
        }

        return null;
    }

    private static function classifySimilarNotDuplicate(
        Client $a,
        Client $b,
        float $sim,
        bool $sameUnp,
        bool $samePhone,
        bool $sameEmail,
        bool $differentUnp,
        bool $differentNames,
    ): ?SimilarPair {
        if ($samePhone && $differentUnp && $differentNames) {
            return new SimilarPair(
                $a->clientId,
                $b->clientId,
                sprintf(
                    'одинаковый телефон %s, но разные УНП (%s / %s) и разные названия — вероятно, общий офис/секретарь, не дублировать',
                    $a->phoneNorm,
                    $a->unpNorm ?? '—',
                    $b->unpNorm ?? '—',
                ),
            );
        }

        if ($sameEmail && $differentUnp && $differentNames) {
            return new SimilarPair(
                $a->clientId,
                $b->clientId,
                sprintf(
                    'одинаковый email %s, разные УНП и названия — общий ящик бухгалтерии, не дублировать',
                    $a->emailNorm,
                ),
            );
        }

        if ($differentUnp && self::isNameSubstring($a->nameNorm, $b->nameNorm)) {
            return new SimilarPair(
                $a->clientId,
                $b->clientId,
                sprintf('«%s» ⊂ «%s», разные УНП — разные юрлица, не дублировать', self::shorterName($a->name, $b->name), self::longerName($a->name, $b->name)),
            );
        }

        if ($sim >= self::NAME_SIMILARITY_MANUAL_ONLY_THRESHOLD && $differentUnp && !$samePhone && !$sameEmail) {
            return new SimilarPair(
                $a->clientId,
                $b->clientId,
                sprintf('схожесть названий %.0f%%, разные УНП и нет общих контактов — похожие названия, разные компании', $sim),
            );
        }

        return null;
    }

    private static function isNameSubstring(string $normA, string $normB): bool
    {
        if ($normA === '' || $normB === '') {
            return false;
        }
        if ($normA === $normB) {
            return false;
        }
        if (str_contains($normA, $normB) || str_contains($normB, $normA)) {
            $shorter = strlen($normA) <= strlen($normB) ? $normA : $normB;
            if (strlen($shorter) < 3) {
                return false;
            }

            return true;
        }

        return false;
    }

    private static function shorterName(string $a, string $b): string
    {
        return mb_strlen($a) <= mb_strlen($b) ? $a : $b;
    }

    private static function longerName(string $a, string $b): string
    {
        return mb_strlen($a) >= mb_strlen($b) ? $a : $b;
    }

    /**
     * @param array<string, DuplicateEdge> $edges
     * @param array<string, Client> $byId
     * @return list<DuplicateGroup>
     */
    private static function buildGroups(array $edges, array $byId): array
    {
        if ($edges === []) {
            return [];
        }

        $ufExact = new UnionFind(array_keys($byId));
        $ufAll = new UnionFind(array_keys($byId));

        foreach ($edges as $edge) {
            if ($edge->level === DuplicateEdge::LEVEL_EXACT) {
                $ufExact->union($edge->clientIdA, $edge->clientIdB);
            }
            $ufAll->union($edge->clientIdA, $edge->clientIdB);
        }

        $groupMembers = [];
        $groupEdges = [];
        foreach ($edges as $edge) {
            $root = $ufAll->find($edge->clientIdA);
            $groupEdges[$root][] = $edge;
            $groupMembers[$root][$edge->clientIdA] = true;
            $groupMembers[$root][$edge->clientIdB] = true;
        }

        $groups = [];
        foreach ($groupMembers as $root => $members) {
            $memberIds = array_keys($members);
            sort($memberIds);
            if (count($memberIds) < 2) {
                continue;
            }

            $edgeList = $groupEdges[$root] ?? [];
            usort($edgeList, static function (DuplicateEdge $x, DuplicateEdge $y): int {
                $c = strcmp($x->clientIdA, $y->clientIdA);
                if ($c !== 0) {
                    return $c;
                }

                return strcmp($x->clientIdB, $y->clientIdB);
            });

            $minLevel = DuplicateEdge::LEVEL_EXACT;
            foreach ($edgeList as $e) {
                if ($e->level === DuplicateEdge::LEVEL_MANUAL) {
                    $minLevel = DuplicateEdge::LEVEL_MANUAL;
                }
            }

            $exactCompSize = [];
            foreach ($memberIds as $id) {
                $exactRoot = $ufExact->find($id);
                $exactCompSize[$exactRoot] = ($exactCompSize[$exactRoot] ?? 0) + 1;
            }

            $mergesViaWeakLink = false;
            foreach ($edgeList as $e) {
                if ($e->level !== DuplicateEdge::LEVEL_MANUAL) {
                    continue;
                }
                $ra = $ufExact->find($e->clientIdA);
                $rb = $ufExact->find($e->clientIdB);
                if ($ra !== $rb
                    && ($exactCompSize[$ra] ?? 0) >= 2
                    && ($exactCompSize[$rb] ?? 0) >= 2) {
                    $mergesViaWeakLink = true;
                    break;
                }
            }

            $label = $minLevel === DuplicateEdge::LEVEL_EXACT ? 'точно' : 'проверить вручную';
            $groups[] = new DuplicateGroup($memberIds, $label, $edgeList, $mergesViaWeakLink);
        }

        usort($groups, static function (DuplicateGroup $g1, DuplicateGroup $g2) use ($byId): int {
            $m1 = self::pickMainClientId($g1->clientIds, $byId, []);
            $m2 = self::pickMainClientId($g2->clientIds, $byId, []);

            return strcmp($m1, $m2);
        });

        return $groups;
    }

    /**
     * @param list<string> $clientIds
     * @param array<string, Client> $byId
     * @param array<string, int> $ticketCounts
     */
    public static function pickMainClientId(array $clientIds, array $byId, array $ticketCounts): string
    {
        usort($clientIds, static function (string $idA, string $idB) use ($byId, $ticketCounts): int {
            $a = $byId[$idA];
            $b = $byId[$idB];
            $ta = $ticketCounts[$idA] ?? 0;
            $tb = $ticketCounts[$idB] ?? 0;
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
            $fa = $a->filledFieldsCount();
            $fb = $b->filledFieldsCount();
            if ($fa !== $fb) {
                return $fb <=> $fa;
            }
            $da = $a->createdAt;
            $db = $b->createdAt;
            if ($da !== $db) {
                return strcmp($da, $db);
            }

            return strcmp($idA, $idB);
        });

        return $clientIds[0];
    }

    public static function pairKey(string $a, string $b): string
    {
        if (strcmp($a, $b) <= 0) {
            return $a . '|' . $b;
        }

        return $b . '|' . $a;
    }

    public static function orderedPair(string $a, string $b): array
    {
        if (strcmp($a, $b) <= 0) {
            return [$a, $b];
        }

        return [$b, $a];
    }
}

final class UnionFind
{
    /** @var array<string, string> */
    private array $parent;

    /** @param list<string> $ids */
    public function __construct(array $ids)
    {
        $this->parent = [];
        foreach ($ids as $id) {
            $this->parent[$id] = $id;
        }
    }

    public function find(string $id): string
    {
        if ($this->parent[$id] !== $id) {
            $this->parent[$id] = $this->find($this->parent[$id]);
        }

        return $this->parent[$id];
    }

    public function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra === $rb) {
            return;
        }
        if (strcmp($ra, $rb) < 0) {
            $this->parent[$rb] = $ra;
        } else {
            $this->parent[$ra] = $rb;
        }
    }
}