<?php

namespace App\Repositories;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class ChartRepository
{
    /**
     * Retorna o número total de cliques.
     * Pode ser filtrado por usuário ou por link.
     */
    public function totalClicks($userId = null, $linkId = null)
    {
        return Click::when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
                    ->when($linkId, fn($query) => $query->where('link_id', $linkId))
                    ->count();
    }

    /**
     * Retorna a contagem de cliques por dia.
     * Pode ser filtrado por usuário ou por link.
     */
    public function clicksByDay($days = 30, $userId = null, $linkId = null)
    {
        return Click::select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit($days)
            ->get();
    }

    /**
     * Retorna a quantidade de cliques agrupados por país.
     */
    public function clicksByCountry($userId = null, $linkId = null)
    {
        return Click::select('country', DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('country')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retorna a quantidade de cliques agrupados por cidade.
     */
    public function clicksByCity($userId = null, $linkId = null)
    {
        return Click::select('city', DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('city')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retorna a quantidade de cliques agrupados por tipo de dispositivo.
     */
    public function clicksByDevice($userId = null, $linkId = null)
    {
        return Click::select('device', DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('device')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retorna os cliques agrupados por referer (origem de tráfego).
     */
    public function clicksByReferer($userId = null, $linkId = null)
    {
        return Click::select('referer', DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('referer')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retorna a quantidade de cliques por campanha UTM.
     */
    public function clicksByCampaign($userId = null, $linkId = null)
    {
        return DB::table('link_utms')
            ->join('clicks', 'clicks.id', '=', 'link_utms.click_id')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->when($userId, fn($query) => $query->where('links.user_id', $userId))
            ->when($linkId, fn($query) => $query->where('links.id', $linkId))
            ->select('link_utms.utm_campaign', DB::raw('COUNT(*) as total'))
            ->groupBy('link_utms.utm_campaign')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Retorna os cliques por dia para um link específico ou todos os links.
     */
    public function clicksPerLinkByDay($linkId = null, $days = 30)
    {
        return Click::select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit($days)
            ->get();
    }

    /**
     * Retorna os links mais acessados ordenados por cliques.
     */
    public function topLinks($limit = 10, $userId = null)
    {
        return Link::withCount('clicks')
            ->when($userId, fn($query) => $query->where('user_id', $userId))
            ->orderByDesc('clicks_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Retorna a quantidade de cliques por usuário (criador dos links).
     */
    public function clicksByUser()
    {
        return User::select('users.id', 'users.name', DB::raw('COUNT(clicks.id) as total_clicks'))
            ->join('links', 'users.id', '=', 'links.user_id')
            ->join('clicks', 'links.id', '=', 'clicks.link_id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_clicks')
            ->get();
    }

    /**
     * Retorna os cliques agrupados por link e por dia.
     */
    public function clicksGroupedByLinkAndDay($userId = null, $linkId = null)
    {
        return Click::select('link_id', DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('link_id', 'day')
            ->orderByDesc('day')
            ->get();
    }

    /**
     * Retorna a quantidade de cliques agrupados por user agent.
     */
    public function clicksByUserAgent($userId = null, $linkId = null)
    {
        return Click::select('user_agent', DB::raw('COUNT(*) as total'))
            ->when($userId, fn($query) => $query->whereHas('link', fn($q) => $q->where('user_id', $userId)))
            ->when($linkId, fn($query) => $query->where('link_id', $linkId))
            ->groupBy('user_agent')
            ->orderByDesc('total')
            ->get();
    }

    /**
 * Retorna a quantidade de links criados por dia.
 */
public function linksCreatedByDay($userId = null, $days = 30)
{
    return Link::select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
        ->when($userId, fn($query) => $query->where('user_id', $userId))
        ->groupBy('day')
        ->orderByDesc('day')
        ->limit($days)
        ->get();
}
}
