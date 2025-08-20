<?php

namespace App\Services;

use App\Repositories\ChartRepository;

class ChartService
{
    protected ChartRepository $chartRepository;

    public function __construct(ChartRepository $chartRepository)
    {
        $this->chartRepository = $chartRepository;
    }

    public function getAllCharts($userId = null, $linkId = null): array
    {
        return [
            'total_clicks'         => $this->chartRepository->totalClicks($userId, $linkId),
            'clicks_by_day'        => $this->chartRepository->clicksByDay(30, $userId, $linkId),
            'clicks_by_country'    => $this->chartRepository->clicksByCountry($userId, $linkId),
            'clicks_by_city'       => $this->chartRepository->clicksByCity($userId, $linkId),
            'clicks_by_device'     => $this->chartRepository->clicksByDevice($userId, $linkId),
            'clicks_by_user_agent' => $this->chartRepository->clicksByUserAgent($userId, $linkId),
            'clicks_by_referer'    => $this->chartRepository->clicksByReferer($userId, $linkId),
            'clicks_by_campaign'   => $this->chartRepository->clicksByCampaign($userId, $linkId),
            'top_links'            => $this->chartRepository->topLinks(10, $userId),
            'clicks_by_user'       => $this->chartRepository->clicksByUser(),
            'clicks_grouped_by_link_and_day' => $this->chartRepository->clicksGroupedByLinkAndDay($userId, $linkId),
            'links_created_by_day' => $this->chartRepository->linksCreatedByDay($userId, 30),
        ];
    }
}
