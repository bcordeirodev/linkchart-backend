# Onboarding Demo Link Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ao criar conta, o usuário recebe automaticamente um link de demo com ~1.200 clicks realísticos para mostrar o potencial do sistema.

**Architecture:** `UserObserver::created()` dispara `SeedDemoLinkJob` (assíncrono, ShouldQueue). O job delega para `OnboardingDemoDataService` que cria o link e insere os clicks em batch. A coluna `is_demo` na tabela `links` identifica o link como demonstração.

**Tech Stack:** Laravel 12 / PHP 8.2, Eloquent, PostgreSQL 15, Redis (queue driver), Docker

---

## File Map

| Ação | Arquivo |
|---|---|
| Criar | `database/migrations/2026_04_30_000001_add_is_demo_to_links_table.php` |
| Modificar | `app/Models/Link.php` |
| Criar | `app/Services/Onboarding/OnboardingDemoDataService.php` |
| Criar | `app/Jobs/SeedDemoLinkJob.php` |
| Criar | `app/Models/Observers/UserObserver.php` |
| Modificar | `app/Providers/AppServiceProvider.php` |

---

## Task 1: Migration — adicionar coluna `is_demo` na tabela `links`

**Files:**
- Create: `database/migrations/2026_04_30_000001_add_is_demo_to_links_table.php`

- [ ] **Step 1: Criar o arquivo de migration**

Crie o arquivo `database/migrations/2026_04_30_000001_add_is_demo_to_links_table.php` com o conteúdo:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
```

- [ ] **Step 2: Rodar a migration via Docker**

```bash
docker-compose exec app php artisan migrate
```

Saída esperada:
```
INFO  Running migrations.
  2026_04_30_000001_add_is_demo_to_links_table ........... 7ms DONE
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_30_000001_add_is_demo_to_links_table.php
git commit -m "feat(onboarding): add is_demo column to links table"
```

---

## Task 2: Atualizar o model `Link`

**Files:**
- Modify: `app/Models/Link.php:16-44`

- [ ] **Step 1: Adicionar `is_demo` em `$fillable`**

No arquivo `app/Models/Link.php`, localize o array `$fillable` (linha 16) e adicione `'is_demo'` após `'is_active'`:

```php
    protected $fillable = [
        'id',
        'slug',
        'original_url',
        'title',
        'description',
        'user_id',
        'expires_at',
        'starts_in',
        'is_active',
        'is_demo',
        'clicks',
        'click_limit',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'created_at',
        'updated_at',
        'health_status',
        'health_checked_at',
    ];
```

- [ ] **Step 2: Adicionar `is_demo` em `$casts`**

No mesmo arquivo, localize o array `$casts` (linha 39) e adicione o cast:

```php
    protected $casts = [
        'expires_at' => 'datetime',
        'starts_in' => 'datetime',
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'health_checked_at' => 'datetime',
    ];
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Link.php
git commit -m "feat(onboarding): add is_demo to Link fillable and casts"
```

---

## Task 3: Criar `OnboardingDemoDataService`

**Files:**
- Create: `app/Services/Onboarding/OnboardingDemoDataService.php`

- [ ] **Step 1: Criar o diretório e o arquivo**

```bash
mkdir -p app/Services/Onboarding
```

Crie o arquivo `app/Services/Onboarding/OnboardingDemoDataService.php`:

```php
<?php

namespace App\Services\Onboarding;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class OnboardingDemoDataService
{
    private const TOTAL_CLICKS = 1200;
    private const BATCH_SIZE = 500;
    private const DAYS_BACK = 60;

    private array $countries = [
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',      'continent' => 'NA'],
        ['name' => 'Brazil',        'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',     'continent' => 'SA'],
        ['name' => 'United Kingdom','iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London',         'continent' => 'EU'],
        ['name' => 'Germany',       'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',         'continent' => 'EU'],
        ['name' => 'France',        'iso' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris',          'continent' => 'EU'],
        ['name' => 'Canada',        'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto',       'continent' => 'NA'],
        ['name' => 'Australia',     'iso' => 'AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney',      'continent' => 'OC'],
        ['name' => 'Japan',         'iso' => 'JP', 'currency' => 'JPY', 'timezone' => 'Asia/Tokyo',            'continent' => 'AS'],
        ['name' => 'India',         'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata',          'continent' => 'AS'],
        ['name' => 'Mexico',        'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City',   'continent' => 'NA'],
        ['name' => 'Spain',         'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',         'continent' => 'EU'],
        ['name' => 'Italy',         'iso' => 'IT', 'currency' => 'EUR', 'timezone' => 'Europe/Rome',           'continent' => 'EU'],
        ['name' => 'Netherlands',   'iso' => 'NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam',      'continent' => 'EU'],
        ['name' => 'Argentina',     'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires',  'continent' => 'SA'],
        ['name' => 'South Korea',   'iso' => 'KR', 'currency' => 'KRW', 'timezone' => 'Asia/Seoul',            'continent' => 'AS'],
        ['name' => 'China',         'iso' => 'CN', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai',         'continent' => 'AS'],
        ['name' => 'Russia',        'iso' => 'RU', 'currency' => 'RUB', 'timezone' => 'Europe/Moscow',         'continent' => 'EU'],
        ['name' => 'Turkey',        'iso' => 'TR', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',       'continent' => 'EU'],
        ['name' => 'Poland',        'iso' => 'PL', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw',         'continent' => 'EU'],
        ['name' => 'Sweden',        'iso' => 'SE', 'currency' => 'SEK', 'timezone' => 'Europe/Stockholm',      'continent' => 'EU'],
    ];

    private array $cities = [
        'US' => [
            ['city' => 'New York',    'state' => 'NY', 'state_name' => 'New York',    'lat' => 40.7128,  'lng' => -74.0060,  'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California',  'lat' => 34.0522,  'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Chicago',     'state' => 'IL', 'state_name' => 'Illinois',    'lat' => 41.8781,  'lng' => -87.6298,  'postal' => '60601'],
            ['city' => 'Houston',     'state' => 'TX', 'state_name' => 'Texas',       'lat' => 29.7604,  'lng' => -95.3698,  'postal' => '77001'],
            ['city' => 'Miami',       'state' => 'FL', 'state_name' => 'Florida',     'lat' => 25.7617,  'lng' => -80.1918,  'postal' => '33101'],
        ],
        'BR' => [
            ['city' => 'São Paulo',       'state' => 'SP', 'state_name' => 'São Paulo',       'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro',  'state' => 'RJ', 'state_name' => 'Rio de Janeiro',  'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília',        'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Salvador',        'state' => 'BA', 'state_name' => 'Bahia',            'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40070-110'],
            ['city' => 'Belo Horizonte',  'state' => 'MG', 'state_name' => 'Minas Gerais',    'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
        ],
        'GB' => [
            ['city' => 'London',     'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
            ['city' => 'Manchester', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4808, 'lng' => -2.2426, 'postal' => 'M1 1AA'],
            ['city' => 'Birmingham', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 52.4862, 'lng' => -1.8904, 'postal' => 'B1 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin',    'state' => 'BE', 'state_name' => 'Berlin',  'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich',    'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
            ['city' => 'Hamburg',   'state' => 'HH', 'state_name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937,  'postal' => '20095'],
        ],
        'FR' => [
            ['city' => 'Paris',     'state' => 'IDF', 'state_name' => 'Île-de-France',              'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon',      'state' => 'ARA', 'state_name' => 'Auvergne-Rhône-Alpes',       'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
            ['city' => 'Marseille', 'state' => 'PAC', 'state_name' => 'Provence-Alpes-Côte d\'Azur','lat' => 43.2965, 'lng' => 5.3698, 'postal' => '13001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0.0, 'lng' => 0.0, 'postal' => '00000'],
        ],
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; Pixel 4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.91 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 11; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Safari/537.36',
        ],
        'bot' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        ],
    ];

    private array $referrers = [
        'social' => [
            'https://www.facebook.com/',
            'https://twitter.com/',
            'https://www.instagram.com/',
            'https://www.linkedin.com/',
            'https://www.tiktok.com/',
        ],
        'search' => [
            'https://www.google.com/search?q=example',
            'https://www.bing.com/search?q=example',
            'https://duckduckgo.com/?q=example',
        ],
        'direct' => [null, null, null],
        'other' => [
            'https://news.ycombinator.com/',
            'https://www.reddit.com/',
            'https://medium.com/',
        ],
    ];

    public function run(User $user): void
    {
        if (Link::where('user_id', $user->id)->where('is_demo', true)->exists()) {
            return;
        }

        $slug = $this->generateUniqueSlug();

        $link = Link::create([
            'user_id'      => $user->id,
            'slug'         => $slug,
            'original_url' => 'https://www.google.com',
            'title'        => 'Exemplo — Google',
            'is_active'    => true,
            'is_demo'      => true,
            'clicks'       => 0,
        ]);

        $this->insertClicks($link->id);

        $link->update(['clicks' => self::TOTAL_CLICKS]);
    }

    private function generateUniqueSlug(): string
    {
        do {
            $slug = Str::random(8);
        } while (Link::where('slug', $slug)->exists());

        return $slug;
    }

    private function insertClicks(int $linkId): void
    {
        $batch = [];
        $start = Carbon::now()->subDays(self::DAYS_BACK);
        $end   = Carbon::now();

        for ($i = 0; $i < self::TOTAL_CLICKS; $i++) {
            $country  = $this->selectCountryByWeight();
            $cityData = $this->getCityData($country['iso']);
            $device   = $this->selectDeviceByWeight();
            $clickAt  = $this->generateRandomDate($start, $end);

            $batch[] = [
                'link_id'    => $linkId,
                'ip'         => $this->generateRealisticIp($country['iso']),
                'user_agent' => $this->getUserAgent($device),
                'referer'    => $this->getReferer(),
                'country'    => $country['name'],
                'iso_code'   => $country['iso'],
                'state'      => $cityData['state'],
                'state_name' => $cityData['state_name'],
                'city'       => $cityData['city'],
                'postal_code'=> $cityData['postal'],
                'latitude'   => $cityData['lat'],
                'longitude'  => $cityData['lng'],
                'timezone'   => $country['timezone'],
                'continent'  => $country['continent'],
                'currency'   => $country['currency'],
                'device'     => $device,
                'created_at' => $clickAt,
                'updated_at' => $clickAt,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                Click::insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Click::insert($batch);
        }
    }

    private function generateRandomDate(Carbon $start, Carbon $end): Carbon
    {
        $timestamp = mt_rand($start->timestamp, $end->timestamp);
        $hour      = $this->getRealisticHour();

        return Carbon::createFromTimestamp($timestamp)->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
    }

    private function getRealisticHour(): int
    {
        $weights = [
            0 => 1,  1 => 1,  2 => 1,  3 => 1,  4 => 1,  5 => 2,
            6 => 3,  7 => 5,  8 => 8,  9 => 10, 10 => 12, 11 => 13,
            12 => 14, 13 => 15, 14 => 16, 15 => 17, 16 => 16,
            17 => 15, 18 => 14, 19 => 13, 20 => 12, 21 => 10,
            22 => 8,  23 => 5,
        ];

        return $this->weightedRandom($weights);
    }

    private function selectCountryByWeight(): array
    {
        $weights = [
            0 => 30, 1 => 20, 2 => 10, 3 => 8,  4 => 6,
            5 => 5,  6 => 4,  7 => 3,  8 => 3,  9 => 3,
            10 => 2, 11 => 2, 12 => 1, 13 => 1, 14 => 1,
            15 => 1, 16 => 1, 17 => 1, 18 => 1, 19 => 1,
        ];

        return $this->countries[$this->weightedRandom($weights)];
    }

    private function getCityData(string $iso): array
    {
        $cities = $this->cities[$iso] ?? $this->cities['DEFAULT'];

        return $cities[array_rand($cities)];
    }

    private function selectDeviceByWeight(): string
    {
        return $this->weightedRandom(['mobile' => 60, 'desktop' => 35, 'tablet' => 4, 'bot' => 1]);
    }

    private function getUserAgent(string $device): string
    {
        $agents = $this->userAgents[$device] ?? $this->userAgents['desktop'];

        return $agents[array_rand($agents)];
    }

    private function getReferer(): ?string
    {
        $type     = $this->weightedRandom(['direct' => 40, 'social' => 30, 'search' => 20, 'other' => 10]);
        $referers = $this->referrers[$type];
        $referer  = $referers[array_rand($referers)];

        return $referer ?: null;
    }

    private function generateRealisticIp(string $iso): string
    {
        $ranges = [
            'US' => ['173.252.', '199.16.',  '204.15.'],
            'BR' => ['200.160.', '189.85.',  '177.67.'],
            'GB' => ['81.2.',    '86.1.',    '109.144.'],
            'DE' => ['85.25.',   '91.65.',   '178.25.'],
            'FR' => ['78.192.',  '90.84.',   '176.31.'],
            'CA' => ['142.11.',  '192.99.',  '198.50.'],
            'AU' => ['203.208.', '220.101.', '139.130.'],
            'JP' => ['202.216.', '210.248.', '126.19.'],
            'IN' => ['103.21.',  '103.22.',  '103.23.'],
            'MX' => ['189.203.', '187.141.', '201.144.'],
        ];

        $prefixes = $ranges[$iso] ?? ['192.168.', '10.0.', '203.0.'];
        $prefix   = $prefixes[array_rand($prefixes)];

        return $prefix . mt_rand(1, 254) . '.' . mt_rand(1, 254);
    }

    private function weightedRandom(array $weights): int|string
    {
        $total   = array_sum($weights);
        $random  = mt_rand(1, $total);
        $current = 0;

        foreach ($weights as $key => $weight) {
            $current += $weight;
            if ($random <= $current) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Onboarding/OnboardingDemoDataService.php
git commit -m "feat(onboarding): add OnboardingDemoDataService with 1200 realistic clicks"
```

---

## Task 4: Criar `SeedDemoLinkJob`

**Files:**
- Create: `app/Jobs/SeedDemoLinkJob.php`

- [ ] **Step 1: Criar o arquivo do job**

Crie o arquivo `app/Jobs/SeedDemoLinkJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Onboarding\OnboardingDemoDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SeedDemoLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    public function handle(OnboardingDemoDataService $service): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        $service->run($user);
    }

    public function failed(\Throwable $e): void
    {
        \Log::channel('api_errors')->error('SeedDemoLinkJob failed', [
            'user_id' => $this->userId,
            'message' => $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Jobs/SeedDemoLinkJob.php
git commit -m "feat(onboarding): add SeedDemoLinkJob"
```

---

## Task 5: Criar `UserObserver` e registrá-lo

**Files:**
- Create: `app/Models/Observers/UserObserver.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Criar o diretório e o observer**

```bash
mkdir -p app/Models/Observers
```

Crie o arquivo `app/Models/Observers/UserObserver.php`:

```php
<?php

namespace App\Models\Observers;

use App\Jobs\SeedDemoLinkJob;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        SeedDemoLinkJob::dispatch($user->id);
    }
}
```

- [ ] **Step 2: Registrar o observer em `AppServiceProvider::boot()`**

No arquivo `app/Providers/AppServiceProvider.php`, adicione o `use` no topo e a chamada no início do método `boot()`:

Adicione após o namespace/use existentes:
```php
use App\Models\Observers\UserObserver;
use App\Models\User;
```

Adicione como primeira linha do método `boot()`:
```php
    public function boot(): void
    {
        User::observe(UserObserver::class);

        // ... restante do método inalterado
```

O arquivo completo ficará assim:

```php
<?php

namespace App\Providers;

use App\Models\Observers\UserObserver;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Repositories\LinkRepositoryInterface::class,
            \App\Repositories\LinkRepository::class
        );

        $this->app->bind(
            \App\Contracts\Services\LinkServiceInterface::class,
            \App\Services\Links\LinkService::class
        );

        $this->app->bind(\App\Contracts\Analytics\DashboardAnalyticsInterface::class,  \App\Services\Analytics\DashboardAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\GeographicAnalyticsInterface::class, \App\Services\Analytics\GeographicAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\TemporalAnalyticsInterface::class,   \App\Services\Analytics\TemporalAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\AudienceAnalyticsInterface::class,   \App\Services\Analytics\AudienceAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\InsightsAnalyticsInterface::class,   \App\Services\Analytics\InsightsAnalyticsService::class);
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);

        Request::macro('isApiRequest', function (): bool {
            /** @var Request $this */
            return $this->expectsJson() || $this->is('api/*');
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') ?: $request->ip());
        });

        RateLimiter::for('public-shorten', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('public-analytics', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('redirect', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Observers/UserObserver.php app/Providers/AppServiceProvider.php
git commit -m "feat(onboarding): register UserObserver to dispatch SeedDemoLinkJob on user creation"
```

---

## Task 6: Smoke Test Manual

- [ ] **Step 1: Garantir que o queue worker está rodando**

```bash
docker-compose exec app php artisan queue:work --once
```

(Deixar rodando em segundo plano ou usar `queue:work` sem `--once` durante o teste.)

- [ ] **Step 2: Registrar um novo usuário via API**

```bash
curl -s -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Teste Demo","email":"demo-test@example.com","password":"password123","password_confirmation":"password123"}' \
  | jq .
```

Saída esperada: `"success": true` com `token` JWT.

- [ ] **Step 3: Processar o job na queue**

```bash
docker-compose exec app php artisan queue:work --once
```

Saída esperada: `App\Jobs\SeedDemoLinkJob ... DONE`

- [ ] **Step 4: Verificar o link demo no banco**

```bash
docker-compose exec app php artisan tinker --execute="
\$user = App\Models\User::where('email', 'demo-test@example.com')->first();
\$link = App\Models\Link::where('user_id', \$user->id)->where('is_demo', true)->first();
echo 'Link: ' . \$link->title . PHP_EOL;
echo 'Slug: ' . \$link->slug . PHP_EOL;
echo 'Clicks counter: ' . \$link->clicks . PHP_EOL;
echo 'Clicks na tabela: ' . \$link->clicks()->count() . PHP_EOL;
"
```

Saída esperada:
```
Link: Exemplo — Google
Slug: <8 chars aleatórios>
Clicks counter: 1200
Clicks na tabela: 1200
```

- [ ] **Step 5: Limpar usuário de teste**

```bash
docker-compose exec app php artisan tinker --execute="
App\Models\User::where('email', 'demo-test@example.com')->first()?->delete();
"
```
