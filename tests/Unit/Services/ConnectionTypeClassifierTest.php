<?php

namespace Tests\Unit\Services;

use App\Services\Links\ClickVelocityService;
use App\Services\Links\LinkTrackingService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers classifyConnectionType, including the ASN-organization path. The ASN
 * lookup is stubbed via an anonymous subclass so no .mmdb file is needed.
 */
class ConnectionTypeClassifierTest extends TestCase
{
    /**
     * Builds a service whose ASN lookup returns a fixed organization name.
     *
     * Uses an anonymous subclass to override lookupAsnOrganization without
     * requiring a real GeoLite2-ASN.mmdb file. The parent constructor receives
     * a real ClickVelocityService resolved from the container so the rest of
     * the service remains fully functional.
     *
     * @param  string|null  $org  The AS organization string the stub should return,
     *                            or null to simulate an absent ASN database.
     */
    private function serviceWithAsnOrg(?string $org): LinkTrackingService
    {
        $velocity = app(ClickVelocityService::class);

        return new class($velocity, $org) extends LinkTrackingService
        {
            public function __construct(
                ClickVelocityService $clickVelocityService,
                private readonly ?string $stubbedOrg,
            ) {
                parent::__construct($clickVelocityService);
            }

            protected function lookupAsnOrganization(string $ip): ?string
            {
                return $this->stubbedOrg;
            }
        };
    }

    /**
     * Invokes the private classifyConnectionType on a service instance.
     *
     * @param  string|null  $isp  ISP string (from GeoIP City) or null.
     * @param  string|null  $ip  Click IP address.
     */
    private function classify(LinkTrackingService $service, ?string $isp, ?string $ip): string
    {
        $method = new ReflectionMethod(LinkTrackingService::class, 'classifyConnectionType');

        return $method->invoke($service, $isp, $ip);
    }

    /**
     * When GeoIP City provides no ISP, the ASN organization name fills in and
     * keywords are matched against it — DigitalOcean maps to 'datacenter'.
     */
    public function test_asn_organization_classifies_datacenter_when_isp_missing(): void
    {
        $service = $this->serviceWithAsnOrg('DigitalOcean, LLC');

        $this->assertSame('datacenter', $this->classify($service, null, '164.90.1.1'));
    }

    /**
     * Mobile carrier keywords in the AS organization name produce 'mobile'
     * even when GeoIP City returns no ISP string.
     */
    public function test_asn_organization_classifies_mobile_when_isp_missing(): void
    {
        $service = $this->serviceWithAsnOrg('Claro NXT Telecomunicacoes Ltda');

        $this->assertSame('mobile', $this->classify($service, null, '177.10.1.1'));
    }

    /**
     * When lookupAsnOrganization returns null (no .mmdb file or IP not found),
     * the legacy CIDR-prefix and keyword behavior is unchanged.
     */
    public function test_falls_back_to_legacy_behavior_without_asn_data(): void
    {
        $service = $this->serviceWithAsnOrg(null);

        $this->assertSame('residential', $this->classify($service, null, '192.168.0.10'));
        $this->assertSame('datacenter', $this->classify($service, 'Amazon Technologies', null));
    }

    /**
     * A non-null ISP from GeoIP City is preferred over the ASN lookup entirely;
     * the stub org is never consulted when the ISP string is already populated.
     */
    public function test_isp_takes_precedence_over_asn_organization(): void
    {
        // Stub returns a mobile org, but the explicit ISP string (datacenter keyword)
        // should win because classifyConnectionType checks $isp first.
        $service = $this->serviceWithAsnOrg('Claro NXT Telecomunicacoes Ltda');

        $this->assertSame('datacenter', $this->classify($service, 'Amazon Technologies', '177.10.1.1'));
    }
}
