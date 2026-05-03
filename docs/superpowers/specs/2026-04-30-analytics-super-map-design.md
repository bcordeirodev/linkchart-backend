# Link Charts — Super Mapa de Analytics

**Data:** 2026-04-30  
**Propósito:** Mapa de referência completo de todas as tabs de analytics — o que existe, o que está coletado mas não exibido, e o que poderia existir. Base para planejamento de features.

---

## Referência Rápida

| Tab | Endpoint | Hook | Refresh |
|-----|----------|------|---------|
| Dashboard | `GET /api/analytics/link/{id}/dashboard?hours=` | `useDashboardData` | 60s |
| Heatmap | `GET /api/analytics/link/{id}/heatmap` | `useHeatmapData` | 30s (realtime) |
| Geographic | `GET /api/analytics/link/{id}/geographic` | `useGeographicData` | manual |
| Temporal | `GET /api/analytics/link/{id}/temporal` | `useTemporalData` | 30s |
| Audience | `GET /api/analytics/link/{id}/audience` | `useAudienceData` | 60s |
| Insights | `GET /api/analytics/link/{id}/insights` | `useInsightsData` | 300s |

Endpoint extra: `GET /api/analytics/link/{id}/comprehensive` (todos os dados num só request, usado pelo `comprehensive` endpoint).  
Endpoint público: `GET /api/public/analytics/{slug}` (básico, sem auth).  
Endpoint executivo: `GET /api/analytics/link/{id}/executive-summary` (top 3 insights, sem tab dedicada).

---

## Tab 1 — Dashboard

### Endpoint & Hook

```
GET /api/analytics/link/{id}/dashboard?hours={1|24|168|720}
Hook: useDashboardData({ linkId, timeframe: '1h'|'24h'|'7d'|'30d' })
Service backend: DashboardAnalyticsService
```

### 1. O que existe hoje

#### 1a. Campos da tabela `clicks` utilizados

| Campo | Uso |
|-------|-----|
| `link_id` | FK para filtrar cliques do link |
| `ip` | Contagem de visitantes únicos |
| `created_at` | Filtro de janela temporal |
| `hour_of_day` | Agregação por hora (0–23) |
| `day_of_week` | Agregação por dia (1–7) |
| `country`, `iso_code` | Top países + contagem de países alcançados |
| `state`, `state_name` | Top estados |
| `city` | Top cidades |
| `device` | Breakdown de dispositivos |
| `browser`, `browser_version` | Distribuição de browsers |
| `os`, `os_version` | Distribuição de sistemas operacionais |
| `is_mobile`, `is_tablet`, `is_desktop` | Classificação de dispositivo |
| `response_time` | Tempo médio de resposta |
| `accept_language` | Distribuição de idiomas |
| `latitude`, `longitude` | Dados do heatmap embutido no dashboard |
| `continent`, `currency`, `timezone` | Metadados geográficos do heatmap |

#### 1b. Payload retornado pela API

```
summary:
  total_clicks         int
  unique_visitors      int
  success_rate         float (0–100)
  avg_response_time    float (ms)
  countries_reached    int
  total_links          int (sempre 1 neste endpoint)
  active_links         int (0 ou 1)
  links_with_traffic   int

link_info:
  id, title, short_url, original_url, clicks, is_active, created_at

temporal_data:
  clicks_by_hour[]          {hour: 0–23, clicks, label: 'HH:00'}
  clicks_by_day_of_week[]   {day: 1–7, day_name, clicks}
  hourly_patterns_local[]   {hour, clicks, avg_response_time, unique_visitors}
  weekend_vs_weekday        {weekend: {clicks, pct, avg_rt}, weekday: {clicks, pct, avg_rt}}
  business_hours_analysis   {business_hours: {...}, non_business_hours: {...}}

geographic_data:
  heatmap_data[]   HeatmapPoint[]
  top_countries[]  {country, iso_code, clicks, currency}
  top_states[]     {country, state, state_name, clicks}
  top_cities[]     {city, state, country, clicks}

audience_data:
  device_breakdown[]     {device, clicks}
  browser_breakdown[]    {browser, clicks}
  os_breakdown[]         {os, clicks}
  browsers[]             {browser, version, clicks, percentage}
  operating_systems[]    {os, version, clicks, percentage}
  device_performance[]   {device, avg_response_time, min_response_time, max_response_time, total_clicks}
  languages[]            {language, clicks, percentage}
```

#### 1c. O que a UI exibe (por componente)

| Componente | Dados renderizados |
|------------|--------------------|
| `LinkInfoCard` | title, short_url, original_url, clicks, is_active |
| `TimeframeSelector` | Seletor de 1h / 24h / 7d / 30d |
| `HourlyClicksChart` | `clicks_by_hour` → gráfico de área |
| `DayOfWeekChart` | `clicks_by_day_of_week` → gráfico de barras |
| `DeviceBreakdownChart` | `device_breakdown` → pie chart |
| `TopCountriesChart` | `top_countries` → bar chart horizontal |
| Summary cards | total_clicks, unique_visitors, avg_response_time, countries_reached |

#### 1d. Gaps — coletado/retornado mas não exibido

| Dado disponível | Localização | Por que é valioso |
|----------------|-------------|-------------------|
| `hourly_patterns_local[].avg_response_time` | payload | Performance por hora do dia |
| `hourly_patterns_local[].unique_visitors` | payload | Unique vs total por hora (implicit bounce rate) |
| `weekend_vs_weekday` | payload | Comparação direta com percentuais |
| `business_hours_analysis` | payload | Padrão comercial vs não-comercial |
| `top_states[]` | payload | Granularidade sub-nacional |
| `top_cities[]` | payload | Granularidade municipal |
| `device_performance[]` (min/max rt) | payload | Outliers de performance |
| `languages[]` | payload | Distribuição de idiomas dos visitantes |
| `browser_version` | payload (em `browsers[]`) | Adoção de versões específicas |
| `os_version` | payload (em `operating_systems[]`) | iOS/Android versioning |
| `success_rate` | payload | Está no card mas pode não ser destacado |
| `currency` | payload (`top_countries`) | Identificação de mercados |

---

### 2. O que poderia existir

#### 2a. Features implementáveis com dados já coletados (custo: só frontend/query)

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| Heatmap hora × dia da semana (matrix 24×7) | `hour_of_day` + `day_of_week` | Alto — revela padrão 2D de engajamento |
| Response time por hora (line chart) | `hourly_patterns_local[].avg_response_time` | Médio — diagnóstico de performance |
| Unique visitors por hora (vs total) | `hourly_patterns_local[].unique_visitors` | Médio — bounce implícito |
| Card: Weekend vs Weekday | `weekend_vs_weekday` | Médio — insight rápido |
| Card: Business hours breakdown | `business_hours_analysis` | Médio — relevante para B2B |
| Chart de idiomas (language breakdown) | `languages[]` | Médio — planejamento de conteúdo |
| Chart top cidades | `top_cities[]` | Médio — granularidade útil para campanhas locais |
| Performance por dispositivo (bar + min/max) | `device_performance[]` | Médio — UX insights |
| Gráfico de versões de browser | `browsers[].version` | Baixo — dev/compat planning |
| Mapa de distribuição por currency | `top_countries[].currency` | Médio — market identification |
| Growth rate (semana passada vs esta) | `weekly_trends` (do endpoint temporal) | Alto — trending |

#### 2b. Dados que poderiam ser capturados (custo: mudança backend/schema)

| Dado | Como capturar | Valor analítico |
|------|--------------|-----------------|
| `utm_source`, `utm_medium`, `utm_campaign` | tabela `link_utm` já existe — falta agregar | Alto — atribuição de campanha |
| `referer` domain aggregation | campo `referer` já existe no schema — falta agregar | Alto — de onde vem o tráfego |
| Click velocity (cliques/min em janelas de 5min) | cálculo sobre `created_at` com window functions | Alto — detecta spikes e bots |
| Time-to-first-click (tempo entre criação do link e 1º clique) | `links.created_at` vs `clicks.created_at` | Médio — viral coefficient |
| Comparison snapshot (semana passada vs esta) | query parametrizada com dois ranges | Alto — contexto de tendência |

#### 2c. Features futuras (novos dados + nova lógica)

- **Dashboard customizável** — drag & drop de widgets, salvar layouts por usuário
- **Alertas automáticos** — threshold de cliques, queda de tráfego, spike incomum
- **Exportação CSV/PDF** — do estado atual do dashboard
- **Comparação de períodos** — "esta semana vs semana passada" com setas de tendência
- **Mini-dashboard público/embeddable** — widget para incorporar em sites externos
- **Dashboard de múltiplos links** — visão agregada de uma campanha (vários links juntos)

---

## Tab 2 — Heatmap

### Endpoint & Hook

```
GET /api/analytics/link/{id}/heatmap
GET /api/analytics/link/{id}/heatmap/realtime  (polling otimizado)
Hook: useHeatmapData({ linkId, enableRealtime: true, refreshInterval: 30000 })
Service backend: GeographicAnalyticsService::getHeatmapData()
```

### 1. O que existe hoje

#### 1a. Campos da tabela `clicks` utilizados

| Campo | Uso |
|-------|-----|
| `latitude`, `longitude` | Posição no mapa |
| `city` | Label do ponto |
| `country` | Agrupamento / tooltip |
| `iso_code` | Código ISO do país |
| `currency` | Metadado por ponto |
| `state_name` | Metadado por ponto |
| `continent` | Metadado por ponto |
| `timezone` | Metadado por ponto |
| `created_at` | `last_click` por agrupamento |

Agrupamento: `GROUP BY latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone`

#### 1b. Payload retornado pela API

```
HeatmapPoint[]:
  lat          float
  lng          float
  city         string
  country      string
  clicks       int
  iso_code     string
  currency     string
  state_name   string
  continent    string
  timezone     string
  last_click   datetime

HeatmapStats (calculado no frontend):
  totalPoints          int
  totalClicks          int
  avgClicksPerPoint    float
  topCountry           string
  topCity              string
  coveragePercentage   float  (uniqueCountries / 195 * 100)
  maxClicks            int
  uniqueCountries      int
  uniqueCities         int
```

#### 1c. O que a UI exibe (por componente)

| Componente | Dados renderizados |
|------------|--------------------|
| `HeatmapMap` (Leaflet) | lat, lng, clicks → heat overlay |
| `HeatmapStats` | totalPoints, totalClicks, topCountry, topCity, uniqueCountries |
| `HeatmapMetrics` | cards de cobertura + intensidade |
| `RealTimeHeatmapChart` | polling a cada 30s |
| `HeatmapControls` | filtros de mapa |

#### 1d. Gaps — disponível mas não exibido

| Dado | Localização | Observação |
|------|-------------|------------|
| `currency` | HeatmapPoint | Não exibido no tooltip nem em chart |
| `continent` | HeatmapPoint | Sem agrupamento/filtro por continente |
| `timezone` | HeatmapPoint | Sem visualização "follow the sun" |
| `last_click` | HeatmapPoint | Não exibido no tooltip do marcador |
| `state_name` | HeatmapPoint | Pode não estar no tooltip |
| Distribuição por continente | calculável | Sem chart de pizza por continente |

---

### 2. O que poderia existir

#### 2a. Features com dados já coletados

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| Tooltip rico no mapa (last_click, timezone, currency) | HeatmapPoint | Médio — contexto por ponto |
| Filtro por continente | `continent` | Médio — drill-down regional |
| Chart de distribuição por continente | `continent` | Médio — panorama macro |
| Visualização "follow the sun" (timezone ring) | `timezone` | Alto — timing de campanhas globais |
| Chart de mercados por moeda | `currency` | Médio — market identification |
| Animação temporal do heatmap | `last_click` ordenado | Alto — replay do crescimento de tráfego |
| Top timezones chart | `timezone` | Médio — quando seu público está acordado |

#### 2b. Dados que poderiam ser capturados

| Dado | Como capturar | Valor |
|------|--------------|-------|
| VPN/proxy flag | GeoIP2 Enterprise (tem campo `is_anonymous_proxy`) | Alto — detecção de tráfego artificial |
| ISP / ASN | GeoIP2 (campo `autonomous_system_organization`) | Médio — data center vs residencial |
| Precisão do GeoIP (radius_km) | GeoIP2 retorna campo de confiança | Baixo — qualidade dos dados no mapa |

#### 2c. Features futuras

- **Heatmap histórico com slider de tempo** — replay visual do crescimento, dia a dia ou semana a semana
- **Filtros no mapa** — por período, por dispositivo, por fonte de tráfego
- **Exportação do mapa como imagem** — para relatórios e apresentações
- **Comparação de dois períodos** — lado a lado ou overlay no mesmo mapa
- **Mapa de market penetration** — cliques / população estimada do país (requer dados externos de população)
- **Detecção visual de clusters de bot** — pontos concentrados de mesmo ISP/ASN

---

## Tab 3 — Geographic

### Endpoint & Hook

```
GET /api/analytics/link/{id}/geographic
Hook: useGeographicData({ linkId })
Service backend: GeographicAnalyticsService::getLinkGeographicAnalytics()
```

### 1. O que existe hoje

#### 1a. Campos da tabela `clicks` utilizados

| Campo | Uso |
|-------|-----|
| `country`, `iso_code` | Top países |
| `state`, `state_name` | Top estados |
| `city` | Top cidades |
| `postal_code` | Capturado mas não agregado aqui |
| `latitude`, `longitude` | Dados do heatmap incluso |
| `continent` | Disponível no heatmap_data |
| `currency` | Disponível no top_countries |
| `timezone` | Disponível no heatmap_data |

#### 1b. Payload retornado pela API

```
heatmap_data[]   HeatmapPoint[]   (igual ao endpoint /heatmap)

top_countries[]
  country     string
  iso_code    string
  clicks      int
  currency    string

top_states[]
  country     string
  state       string
  state_name  string
  clicks      int

top_cities[]
  city        string
  state       string
  country     string
  clicks      int
```

#### 1c. O que a UI exibe

| Componente | Dados renderizados |
|------------|--------------------|
| `GeographicMetrics` | Cards de cobertura (países, cidades alcançadas) |
| `GeographicChart` | Bar chart de top países + top cidades |
| `GeographicInsights` | Observações automáticas geradas |

#### 1d. Gaps — disponível mas não exibido

| Dado | Observação |
|------|------------|
| `postal_code` | Capturado no schema — nunca agregado nem exibido |
| `continent` | Presente no heatmap_data — sem chart de distribuição por continente |
| `currency` | Presente em top_countries — sem chart de distribuição de mercado |
| `top_states[]` | Pode não ter chart dedicado (apenas top_countries e top_cities) |
| Percentuais | top_states e top_cities não retornam `percentage` — comparação relativa ausente |
| Tendência por região | Não há dados de crescimento (semana passada vs esta) por país |

---

### 2. O que poderia existir

#### 2a. Features com dados já coletados

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| Chart top estados com percentual | `top_states[]` + total clicks | Médio |
| Chart top cidades com percentual | `top_cities[]` + total clicks | Médio |
| Chart de distribuição por continente | `heatmap_data[].continent` | Médio |
| Chart de mercados por moeda | `top_countries[].currency` | Médio — market segmentation |
| Mapa choropleth de países | `top_countries[]` + lib de mapas SVG | Alto — visual impactante |
| Top postal codes | `postal_code` — só precisaria de query | Baixo — útil para e-commerce |
| Análise de timezone por país | `heatmap_data[].timezone` | Médio |
| Percentual de cobertura global | `uniqueCountries / 195 * 100` | Baixo — já calculado no heatmap |

#### 2b. Dados que poderiam ser capturados

| Dado | Como capturar | Valor |
|------|--------------|-------|
| Região DMA (Designated Market Area) | GeoIP2 City tem campo `metro_code` (EUA) | Médio — campanhas de mídia nos EUA |
| Nível de confiança do GeoIP | GeoIP2 retorna `accuracy_radius` | Baixo — qualidade dos dados |
| Dados de população por país | API externa (World Bank, REST Countries) | Médio — cliques per capita |

#### 2c. Features futuras

- **Mapa choropleth interativo** — países coloridos por intensidade de cliques com drill-down
- **Análise de mercado: cliques per capita** — índice de penetração por país
- **Comparação de mercados** — país A vs país B ao longo do tempo
- **Oportunidades de mercado** — países com crescimento de tráfego mas baixo volume absoluto
- **Exportação de relatório geográfico** — PDF com mapa + tabelas

---

## Tab 4 — Temporal

### Endpoint & Hook

```
GET /api/analytics/link/{id}/temporal
Hook: useTemporalData({ linkId, enableRealtime: false })
Service backend: TemporalAnalyticsService (básico + advanced)
```

### 1. O que existe hoje

#### 1a. Campos da tabela `clicks` utilizados

| Campo | Uso |
|-------|-----|
| `hour_of_day` | Distribuição por hora (0–23) |
| `day_of_week` | Distribuição por dia (1–7) |
| `day_of_month` | Capturado — não agregado neste endpoint |
| `month`, `year` | Tendências mensais |
| `local_time` | Timestamp local do visitante |
| `is_weekend` | Análise fim de semana |
| `is_business_hours` | Análise horário comercial |
| `created_at` | Cálculo de trends semanais/mensais |
| `response_time` | Média de RT por hora (em `hourly_patterns_local`) |
| `ip` | Unique visitors por hora |
| `timezone` | Distribuição de timezones |

#### 1b. Payload retornado pela API

```
clicks_by_hour[]
  hour    0–23
  clicks  int
  label   'HH:00'

clicks_by_day_of_week[]
  day       1–7
  day_name  string
  clicks    int

hourly_patterns_local[]
  hour              int
  clicks            int
  avg_response_time float (ms)
  unique_visitors   int

weekend_vs_weekday
  weekend:  {clicks, percentage, avg_response_time, unique_visitors}
  weekday:  {clicks, percentage, avg_response_time, unique_visitors}

business_hours_analysis
  business_hours:     {clicks, percentage, avg_response_time}
  non_business_hours: {clicks, percentage, avg_response_time}

advanced.weekly_trends     {'YYYY-WW': int}
advanced.monthly_trends    {'YYYY-MM': int}
advanced.peak_analysis
  peak_hour        0–23
  peak_day         1–7
  peak_day_name    string
  peak_hour_clicks int
  peak_day_clicks  int
advanced.timezone_analysis[]
  name    string
  clicks  int
```

#### 1c. O que a UI exibe

| Componente | Dados renderizados |
|------------|--------------------|
| `TemporalChart` | `clicks_by_hour` (área) + `clicks_by_day_of_week` (barra) |
| `TemporalTrendsChart` | `weekly_trends` + `monthly_trends` |
| `TimezoneDistributionChart` | `timezone_analysis[]` |
| `PeakAnalysisCard` | `peak_hour`, `peak_day`, `peak_day_name` |
| `TemporalInsights` | Observações geradas automaticamente |

#### 1d. Gaps — disponível mas não exibido

| Dado | Localização | Valor não capturado |
|------|-------------|---------------------|
| `hourly_patterns_local[].avg_response_time` | payload | Performance por hora do dia |
| `hourly_patterns_local[].unique_visitors` | payload | Ratio unique/total por hora |
| `weekend_vs_weekday` | payload | Card de comparação direta ausente |
| `business_hours_analysis` | payload | Card de padrão B2B/B2C ausente |
| `day_of_month` | schema | Sem gráfico de padrão por dia do mês |
| Growth rate (WoW, MoM) | calculável de `weekly_trends` | Sem indicadores de tendência percentual |
| Heatmap hora × dia | calculável de `hour_of_day` + `day_of_week` | Feature de alto valor ausente |

---

### 2. O que poderia existir

#### 2a. Features com dados já coletados

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| **Heatmap hora × dia (matrix 24×7)** | `hour_of_day` + `day_of_week` — nova query | **Alto** — padrão 2D mais rico que gráficos 1D |
| Response time por hora (line chart) | `hourly_patterns_local[].avg_response_time` | Médio |
| Unique visitors por hora | `hourly_patterns_local[].unique_visitors` | Médio |
| Growth rate WoW (%) | `weekly_trends` | **Alto** — trending visível |
| Growth rate MoM (%) | `monthly_trends` | **Alto** — tendência de longo prazo |
| Card: Weekend vs Weekday | `weekend_vs_weekday` | Médio |
| Card: Business Hours | `business_hours_analysis` | Médio |
| Gráfico dia do mês (1–31) | `day_of_month` — nova query | Médio — padrões salariais, etc. |
| "Melhor horário para publicar" | `peak_analysis` + confidence | Médio — recomendação acionável |

#### 2b. Dados que poderiam ser capturados

| Dado | Como capturar | Valor |
|------|--------------|-------|
| Inter-click time (tempo entre cliques consecutivos) | Cálculo sobre `created_at` com LAG() window | Alto — detecta bursts orgânicos vs bot |
| Timezone declarado pelo browser | Header `Accept-Timezone` ou JS `Intl.DateTimeFormat` | Médio — mais preciso que GeoIP |
| Holiday/event flags | Comparar `created_at` com calendário de feriados | Médio — contextualizar picos |
| First-click latency (ms entre publicação e 1º clique) | `links.created_at` vs primeiro `clicks.created_at` | Médio — viral coefficient |

#### 2c. Features futuras

- **Heatmap temporal 24×7** — matrix de horas × dias com intensidade de cores, o mais pedido em ferramentas de analytics
- **Previsão de tráfego** — modelo simples (média móvel ou seasonal decomposition) com base nos dados históricos
- **Scheduling recomendado** — "Publique às 19h na terça — é quando seu link tem mais engajamento"
- **Alertas de anomalia temporal** — desvio padrão × média histórica → notificação de spike ou queda
- **Comparação de períodos** — overlay de semanas diferentes no mesmo gráfico

---

## Tab 5 — Audience

### Endpoint & Hook

```
GET /api/analytics/link/{id}/audience
Hook: useAudienceData({ linkId, enableRealtime: true })
Service backend: AudienceAnalyticsService
```

### 1. O que existe hoje

#### 1a. Campos da tabela `clicks` utilizados

| Campo | Uso |
|-------|-----|
| `device` | Breakdown principal (mobile/tablet/desktop) |
| `is_mobile`, `is_tablet`, `is_desktop` | Classificação booleana |
| `browser`, `browser_version` | Distribuição de browsers |
| `os`, `os_version` | Distribuição de OS |
| `is_bot` | Filtrar bots (geralmente excluído) |
| `accept_language` | Distribuição de idiomas |
| `response_time` | Performance por dispositivo |
| `user_agent` | Raw — processado no tracking, nunca exibido |

#### 1b. Payload retornado pela API

```
device_breakdown[]
  device      string
  clicks      int
  percentage  float

browser_breakdown[]
  browser  string
  clicks   int

os_breakdown[]
  os      string
  clicks  int

browsers[]
  browser     string
  version     string
  clicks      int
  percentage  float

operating_systems[]
  os          string
  version     string
  clicks      int
  percentage  float

device_performance[]
  device              string
  avg_response_time   float (ms)
  min_response_time   float (ms)
  max_response_time   float (ms)
  total_clicks        int

languages[]
  language    string
  clicks      int
  percentage  float
```

#### 1c. O que a UI exibe

| Componente | Dados renderizados |
|------------|--------------------|
| `AudienceChart` | `device_breakdown` (pie) + `browser_breakdown` (bar) + `os_breakdown` (bar) |
| `AudienceMetrics` | Cards de dispositivos, top browser, top OS |
| `AudienceInsights` | Observações automáticas |

#### 1d. Gaps — disponível mas não exibido

| Dado | Localização | Valor não capturado |
|------|-------------|---------------------|
| `browser_version` | `browsers[]` | Adoção de versões específicas (Chrome 120 vs 124) |
| `os_version` | `operating_systems[]` | iOS 17 vs 18, Android 14 vs 15 |
| `device_performance[]` | payload | Performance min/max por dispositivo — não exibido graficamente |
| `languages[]` | payload | Chart de idiomas pode não estar em destaque |
| `is_bot` cliques | schema | Nenhuma seção de bot analytics — tráfego de bot é simplesmente filtrado |
| Cross-analysis device × OS | calculável | Ex: iOS/Safari vs Android/Chrome |
| Cross-analysis device × país | calculável | Ex: mobile domina no Brasil |

---

### 2. O que poderia existir

#### 2a. Features com dados já coletados

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| Browser version adoption chart | `browsers[].version` | Médio — dev/compat planning |
| OS version adoption chart | `operating_systems[].version` | Médio |
| Performance por browser (novo) | nova query sobre `browser` + `response_time` | Médio |
| Chart de idiomas em destaque | `languages[]` — já no payload | Médio |
| Bot traffic report | `is_bot = true` — nova query segregada | Médio — qualidade do tráfego |
| Mobile vs Desktop trend over time | audience + temporal combinados | **Alto** |
| Cross-tab: device × país | audience + geographic | Médio |
| Performance range por dispositivo (min/max) | `device_performance[]` | Médio |

#### 2b. Dados que poderiam ser capturados

| Dado | Como capturar | Valor |
|------|--------------|-------|
| Screen resolution | `Sec-CH-Width` / `Sec-CH-Viewport-Width` (Client Hints) | Alto — design responsivo |
| Connection type (4G/5G/WiFi) | `Sec-CH-UA-Downlink` / `ECT` header | Alto — performance planejamento |
| Dark mode preference | `Sec-CH-Prefers-Color-Scheme` header | Baixo — design decision |
| Touch capability | `Sec-CH-UA-Mobile` (mais preciso que UA string) | Baixo |
| Viewport width | JS snippet ou Client Hints | Médio — breakpoints reais |
| Browser engine | derivável de browser (Blink/Gecko/WebKit) | Baixo |
| Referrer full domain | `referer` já existe — falta agregação por domain | **Alto** |

#### 2c. Features futuras

- **Device × OS compatibility matrix** — visualizar qual combinação domina para guiar decisões de desenvolvimento
- **"Otimize sua landing page"** — recomendação automática baseada em device breakdown (ex: "72% mobile — considere AMP ou PWA")
- **Browser compatibility warnings** — alerta se versão legada tem participação relevante
- **Mobile trend ao longo do tempo** — crescimento de mobile MoM
- **Tráfego bot isolado** — seção de "bot traffic" com IPs, frequência e padrão temporal
- **Performance SLA por dispositivo** — alertas se avg_response_time excede threshold por tipo de device

---

## Tab 6 — Insights

### Endpoint & Hook

```
GET /api/analytics/link/{id}/insights
Hook: useInsightsData({ linkId, refreshInterval: 300000 })
Service backend: InsightsAnalyticsService (orquestra 8 geradores)
Endpoint extra: GET /api/analytics/link/{id}/executive-summary (top 3 insights)
```

### 1. O que existe hoje

#### 1a. Campos do schema utilizados (via delegação aos outros services)

Esta tab agrega dados de todos os outros services. Os campos exclusivos desta tab:

| Campo | Uso |
|-------|-----|
| `is_return_visitor` | Taxa de retorno |
| `session_clicks` | Profundidade de sessão |
| `click_source` | Categorização da fonte de tráfego |
| `ip` | Identificar retornantes (24h window) + sessões (1h window) |
| `referer` | Categorização da fonte |
| `created_at` | Janelas de tempo para sessões |

#### 1b. Payload retornado pela API

```
insights[]
  type         'geographic'|'temporal'|'audience'|'performance'|'diversity'|'security'|'engagement'|'retention'
  title        string
  description  string
  priority     'high'|'medium'|'low'
  actionable   bool
  confidence   float (0–1)
  data_points  Record<string, any>

summary
  total_insights       int
  high_priority        int
  actionable_insights  int
  avg_confidence       float

analytics_data.retention
  return_visitor_rate    float (0–1)
  new_visitor_rate       float (0–1)
  total_visitors         int
  return_visitors        int
  new_visitors           int
  retention_score        float
  benchmark_comparison   'excellent'|'good'|'average'|'needs_improvement'

analytics_data.session_depth
  avg_session_depth       float
  max_session_depth       int
  session_distribution[]  {session_clicks, users, percentage}
  power_users_count       int
  power_users_percentage  float
  engagement_score        float
  session_quality         'excellent'|'good'|'average'|'low'|'no_data'
  total_sessions          int

analytics_data.traffic_sources
  sources[]       {source, clicks, percentage, avg_response_time, avg_session_depth}
  channels[]      {channel, clicks, unique_visitors, sources[], avg_response_time, avg_session_depth}
  top_source      {source, clicks, percentage}
  source_diversity int
  total_clicks    int
  recommendations[] {type, message, priority}

generated_at  datetime
```

#### Geradores de insights (8 tipos)

| Gerador | Tipos de insights gerados |
|---------|--------------------------|
| `GeographicInsightGenerator` | Concentração geográfica, expansão de mercado |
| `DeviceInsightGenerator` | Dominância de dispositivo, mobile-first signals |
| `TemporalInsightGenerator` | Horários de pico, padrões recorrentes |
| `PerformanceInsightGenerator` | Lentidão, outliers de response time |
| `DiversityInsightGenerator` | Diversidade de browsers/OS/países |
| `SecurityInsightGenerator` | Padrões anômalos, possível bot traffic |
| `EngagementInsightGenerator` | Session depth, power users |
| `RetentionInsightGenerator` | Retorno de visitantes, churn implícito |

#### 1c. O que a UI exibe

| Componente | Dados renderizados |
|------------|--------------------|
| `BusinessInsights` | Lista de insights com badge de prioridade |
| `RetentionAnalysisChart` | `retention`: score + new vs return visitors |
| `SessionDepthChart` | `session_depth`: distribuição de sessões |
| `TrafficSourceChart` | `traffic_sources`: sources + channels |
| `InsightsAnalysis` | Summary cards + filtros |

#### 1d. Gaps — disponível mas não exibido

| Dado | Localização | Valor não capturado |
|------|-------------|---------------------|
| `data_points` de cada insight | insights[] | Context rico por insight — pode não ser expandível na UI |
| `confidence` por insight | insights[] | Pode ser subexposto — credibilidade do insight |
| `session_distribution[]` granular | analytics_data | Distribuição detalhada pode estar simplificada |
| `recommendations[]` de traffic_sources | analytics_data | Campo mais acionável — pode estar escondido |
| `benchmark_comparison` | retention | Comparação com benchmark — pode não ser visualmente destacada |
| `channels[]` granular | traffic_sources | Breakdown por canal pode estar simplificado |
| `avg_session_depth` por source | traffic_sources.sources[] | Qualidade de engajamento por fonte de tráfego |

---

### 2. O que poderia existir

#### 2a. Features com dados já coletados

| Feature | Dados necessários | Impacto |
|---------|------------------|---------|
| Filtro de insights por tipo | `insights[].type` | Médio — foco em categoria específica |
| Ordenação por confidence × priority | `insights[]` | Médio |
| "Quick wins" view | insights onde `actionable=true AND confidence>0.8` | **Alto** — priorização imediata |
| Expandir data_points de cada insight | `data_points` | Médio — transparência |
| Recommendations em destaque | `recommendations[]` | **Alto** — campo mais acionável do sistema |
| Traffic source × engagement matrix | `channels[].avg_session_depth` | **Alto** — qualidade vs quantidade |
| Histórico de insights (snapshot) | requer persistência no backend | Alto — trending de saúde do link |

#### 2b. Dados que poderiam ser capturados

| Dado | Como capturar | Valor |
|------|--------------|-------|
| UTM parameters (utm_source, utm_medium, utm_campaign) | tabela `link_utm` já existe — integrar nos insights | **Alto** — atribuição de campanha |
| Conversion events | pixel JS ou webhook pós-redirect | **Alto** — ROI real de cada link |
| User journey (sequência de links pelo mesmo IP) | cruzar IP + `link_id` + `created_at` | Alto — comportamento cross-link |
| NPS/feedback pós-redirect | redirect intermediário com popup opcional | Médio — qualidade percebida |
| Open Graph shares (pré-clique) | tracking de renders do preview bot | Médio — compartilhamentos sem clique |
| Link health (URL original ativa/offline) | `health_status` já existe no schema de `links` | Médio — insight de confiabilidade |

#### 2c. Features futuras

- **AI-generated narrative** — LLM transforma os insights em um parágrafo executivo em linguagem natural ("Seu link teve crescimento de 34% esta semana, puxado por tráfego mobile do Brasil…")
- **Insight subscriptions** — digest semanal por email com top insights do link
- **Benchmark entre seus links** — link A vs link B (mesma campanha, diferentes canais)
- **Predictive insights** — forecast de tráfego para a próxima semana com intervalo de confiança
- **Insight severity trending** — este link está melhorando ou piorando no tempo?
- **Insight score compartilhável** — "score de saúde" público do link para uso em apresentações

---

## Apêndice A — Schema Completo da Tabela `clicks`

Todos os campos capturados a cada clique, com cobertura de tab.

| Campo | Tipo | Quais tabs usam | Observação |
|-------|------|----------------|------------|
| `id` | bigint PK | — | |
| `link_id` | bigint FK | Todas | CASCADE DELETE |
| `ip` | string | Dashboard, Insights | Nunca exibido — só para contagem unique |
| `user_agent` | string | Audience | Raw — processado no tracking |
| `referer` | string | Insights | Categorizado em `click_source` — raw nunca exibido |
| `accept_language` | string | Audience (languages) | Parseado para idioma |
| `country` | string | Dashboard, Geographic, Heatmap | |
| `city` | string | Dashboard, Geographic, Heatmap | |
| `iso_code` | string | Dashboard, Geographic, Heatmap | Código ISO 3166-1 |
| `state` | string | Geographic | Código de estado |
| `state_name` | string | Geographic, Heatmap | Nome por extenso |
| `postal_code` | string | **Nunca usado** | Capturado, jamais agregado |
| `latitude` | float | Heatmap | |
| `longitude` | float | Heatmap | |
| `timezone` | string | Temporal, Heatmap | Ex: 'America/Sao_Paulo' |
| `continent` | string | Heatmap | Ex: 'SA', 'EU' |
| `currency` | string | Geographic, Heatmap | Ex: 'BRL', 'USD' |
| `device` | string | Dashboard, Audience | 'mobile'/'tablet'/'desktop'/'bot' |
| `browser` | string | Dashboard, Audience | Nome do browser |
| `browser_version` | string | Audience | Ex: '124.0' |
| `os` | string | Dashboard, Audience | Ex: 'iOS', 'Android' |
| `os_version` | string | Audience | Ex: '17.4' |
| `is_mobile` | boolean | Audience | |
| `is_tablet` | boolean | Audience | |
| `is_desktop` | boolean | Audience | |
| `is_bot` | boolean | **Nunca exibido** | Usado para filtrar, sem seção própria |
| `hour_of_day` | tinyint | Dashboard, Temporal | 0–23 |
| `day_of_week` | tinyint | Dashboard, Temporal | 1–7 (Monday=1) |
| `day_of_month` | tinyint | **Nunca usado** | 1–31, capturado sem uso |
| `month` | tinyint | Temporal | 1–12 |
| `year` | smallint | Temporal | |
| `local_time` | string | — | 'YYYY-MM-DD HH:mm:ss' em timezone local |
| `is_weekend` | boolean | Temporal | |
| `is_business_hours` | boolean | Temporal | Hour 9–17 |
| `is_return_visitor` | boolean | Insights | Click count last 24h > 0 |
| `session_clicks` | int | Insights | Click count last 1h + 1 |
| `click_source` | string | Insights | 'social'/'search'/'email'/'direct'/'referral'/'unknown' |
| `response_time` | decimal(8,3) | Dashboard, Temporal, Audience | ms |
| `created_at` | timestamp | Todas | |
| `updated_at` | timestamp | — | |

**Total: ~40 colunas mapeadas, 3 nunca usadas na UI (`postal_code`, `day_of_month`, `is_bot` sem seção própria).**

---

## Apêndice B — Matrix de Cobertura

Legenda: ✅ implementado e exibido / ⚠️ coletado/retornado mas não exibido / 🔴 não coletado / 💡 oportunidade identificada

| Categoria de dado | Dashboard | Heatmap | Geographic | Temporal | Audience | Insights |
|-------------------|-----------|---------|------------|----------|----------|----------|
| Cliques totais | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Visitantes únicos | ✅ | ⚠️ | 🔴 | ⚠️ | 🔴 | ✅ |
| País / ISO | ✅ | ✅ | ✅ | 🔴 | 🔴 | ⚠️ |
| Estado / Cidade | ⚠️ | ⚠️ | ✅ | 🔴 | 🔴 | 🔴 |
| Postal Code | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Continente | 🔴 | ⚠️ | ⚠️ | 🔴 | 🔴 | 🔴 |
| Lat/Lng (heatmap) | ✅ | ✅ | ✅ | 🔴 | 🔴 | 🔴 |
| Timezone | 🔴 | ⚠️ | 🔴 | ✅ | 🔴 | 🔴 |
| Currency / Market | ⚠️ | ⚠️ | ⚠️ | 🔴 | 🔴 | 🔴 |
| Hora do dia | ✅ | 🔴 | 🔴 | ✅ | 🔴 | ⚠️ |
| Dia da semana | ✅ | 🔴 | 🔴 | ✅ | 🔴 | ⚠️ |
| Dia do mês | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Tendência semanal | 🔴 | 🔴 | 🔴 | ✅ | 🔴 | 🔴 |
| Tendência mensal | 🔴 | 🔴 | 🔴 | ✅ | 🔴 | 🔴 |
| Weekend vs Weekday | ⚠️ | 🔴 | 🔴 | ⚠️ | 🔴 | 🔴 |
| Business Hours | ⚠️ | 🔴 | 🔴 | ⚠️ | 🔴 | 🔴 |
| Device (mobile/desktop) | ✅ | 🔴 | 🔴 | 🔴 | ✅ | ⚠️ |
| Browser | ✅ | 🔴 | 🔴 | 🔴 | ✅ | 🔴 |
| Browser version | ⚠️ | 🔴 | 🔴 | 🔴 | ⚠️ | 🔴 |
| OS | ✅ | 🔴 | 🔴 | 🔴 | ✅ | 🔴 |
| OS version | ⚠️ | 🔴 | 🔴 | 🔴 | ⚠️ | 🔴 |
| Idioma | ⚠️ | 🔴 | 🔴 | 🔴 | ⚠️ | 🔴 |
| Bot traffic | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | ⚠️ |
| Response time médio | ✅ | 🔴 | 🔴 | ⚠️ | ⚠️ | ⚠️ |
| Response time por device | ⚠️ | 🔴 | 🔴 | 🔴 | ⚠️ | 🔴 |
| Fonte de tráfego | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | ✅ |
| Retenção / Return visitors | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | ✅ |
| Session depth | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | ✅ |
| UTM parameters | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Referer domain | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Screen resolution | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Connection type | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| VPN / Proxy | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |
| Conversions | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 | 🔴 |

---

## Apêndice C — Ranking de Oportunidades

### Tier 1 — Zero custo de coleta (dados já existem no schema/payload, só precisam de UI)

| Oportunidade | Tab(s) | Impacto | Esforço UI |
|---|---|---|---|
| Heatmap hora × dia (matrix 24×7) | Temporal | ⭐⭐⭐ Alto | Médio |
| Growth rate WoW/MoM (%) | Temporal | ⭐⭐⭐ Alto | Baixo |
| UTM breakdown (link_utm já existe) | Insights / Dashboard | ⭐⭐⭐ Alto | Médio |
| Referer domain aggregation | Insights / Dashboard | ⭐⭐⭐ Alto | Médio |
| Recommendations[] em destaque | Insights | ⭐⭐⭐ Alto | Baixo |
| "Quick wins" insights view | Insights | ⭐⭐ Médio | Baixo |
| Card: Weekend vs Weekday | Dashboard / Temporal | ⭐⭐ Médio | Baixo |
| Card: Business Hours analysis | Dashboard / Temporal | ⭐⭐ Médio | Baixo |
| Idiomas em destaque (chart) | Dashboard / Audience | ⭐⭐ Médio | Baixo |
| Bot traffic report (is_bot) | Audience | ⭐⭐ Médio | Baixo |
| Response time por hora | Temporal | ⭐⭐ Médio | Baixo |
| Performance por browser | Audience | ⭐⭐ Médio | Baixo |
| Top cidades com % | Geographic | ⭐ Baixo | Baixo |
| Continente (chart + filtro) | Geographic / Heatmap | ⭐ Baixo | Baixo |
| Currency/market chart | Geographic / Heatmap | ⭐ Baixo | Baixo |
| Tooltip rico no mapa (last_click, timezone) | Heatmap | ⭐ Baixo | Baixo |
| "Follow the sun" (timezone ring) | Heatmap | ⭐⭐ Médio | Médio |
| Dia do mês (gráfico 1–31) | Temporal | ⭐ Baixo | Baixo |
| Postal code top list | Geographic | ⭐ Baixo | Baixo |

### Tier 2 — Baixo custo de coleta (campo já existe, só falta agregar/expor)

| Oportunidade | Campo existente | Tab | Impacto |
|---|---|---|---|
| UTM source/medium/campaign breakdown | tabela `link_utm` | Insights, Dashboard | ⭐⭐⭐ Alto |
| Referer domain top list | `clicks.referer` | Dashboard, Insights | ⭐⭐⭐ Alto |
| Click velocity (spikes) | `clicks.created_at` window | Dashboard | ⭐⭐ Médio |
| Inter-click time analysis | `clicks.created_at` LAG() | Insights | ⭐⭐ Médio |
| First-click latency | `links.created_at` vs `clicks` | Insights | ⭐ Médio |
| Time-to-1000-clicks milestone | `clicks.created_at` | Dashboard | ⭐ Baixo |

### Tier 3 — Custo médio (requer nova coluna + lógica de captura)

| Oportunidade | Como capturar | Impacto |
|---|---|---|
| Screen resolution | `Sec-CH-Viewport-Width` (Client Hints) | ⭐⭐ Médio |
| Connection type (4G/5G/WiFi) | `ECT` header | ⭐⭐ Médio |
| VPN/proxy detection | GeoIP2 `is_anonymous_proxy` | ⭐⭐ Médio |
| ISP / ASN | GeoIP2 `autonomous_system_organization` | ⭐⭐ Médio |
| Holiday overlay | Comparar `created_at` com API de feriados | ⭐ Baixo |
| Comparison snapshots (período vs período) | Persistir snapshots no DB | ⭐⭐⭐ Alto |

### Tier 4 — Alto esforço (novas integrações ou subsistemas)

| Oportunidade | Complexidade | Impacto |
|---|---|---|
| Conversion tracking (pixel/webhook) | Nova integração | ⭐⭐⭐ Alto |
| AI-generated narrative insights (LLM) | Nova integração | ⭐⭐⭐ Alto |
| Predictive analytics (forecast) | ML pipeline | ⭐⭐⭐ Alto |
| User journey cross-link | Nova lógica de sessão | ⭐⭐ Médio |
| Heatmap histórico com replay | Storage de snapshots + player | ⭐⭐ Médio |
| Dashboard customizável (drag & drop) | Nova UI layer | ⭐⭐ Médio |
| Alertas automáticos (threshold/anomalia) | Sistema de notificações | ⭐⭐⭐ Alto |
| Benchmark entre links | UI de comparação + query | ⭐⭐ Médio |
| Export PDF/CSV | Rendering + download | ⭐ Baixo |
| Insight subscriptions (email digest) | Email pipeline | ⭐⭐ Médio |
