<?php

namespace App\Services\Analytics\Insights;

class InsightGeneratorRegistry
{
    /** @var InsightGeneratorInterface[] */
    private array $generators = [];

    public function register(InsightGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;
    }

    public function generate(int $linkId, int $totalClicks): array
    {
        $insights = [];
        foreach ($this->generators as $gen) {
            $insight = $gen->generate($linkId, $totalClicks);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }
}
