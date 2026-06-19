<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use app\service\FreightCalculatorService;
use app\service\dto\RegionPathDto;
use PHPUnit\Framework\TestCase;

final class FreightCalculatorServiceTest extends TestCase
{
    private FreightCalculatorService $service;

    protected function setUp(): void
    {
        $this->service = new FreightCalculatorService();
    }

    public function testTemplateIdZeroReturnsFreeShipping(): void
    {
        $result = $this->service->calculate(0, $this->beijingPath(), 1);
        $this->assertSame(0.0, $result->fee);
        $this->assertSame('free', $result->source);
        $this->assertNull($result->matchedRuleId);
    }

    public function testFallsBackToDefaultWhenNoRuleMatches(): void
    {
        $template = $this->baseTemplate();
        $result = $this->service->calculateWithRules(
            template: $template,
            rules: [],
            regionPath: $this->beijingPath(),
            totalCount: 1,
        );
        $this->assertSame('default', $result->source);
        $this->assertSame(10.0, $result->fee); // default_first_fee
    }

    public function testStreetOverridesCityWhenBothPresent(): void
    {
        $template = $this->baseTemplate();
        $rules = [
            $this->makeRule(id: 1, matchLevel: 4, regionIds: [4001], firstFee: 5.0),
            $this->makeRule(id: 2, matchLevel: 2, regionIds: [2001], firstFee: 20.0),
        ];
        $path = new RegionPathDto(
            provinceId: 1001,
            cityId: 2001,
            districtId: 3001,
            streetId: 4001,
        );

        $result = $this->service->calculateWithRules($template, $rules, $path, 1);

        $this->assertSame('rule', $result->source);
        $this->assertSame(1, $result->matchedRuleId);
        $this->assertSame(4, $result->matchedLevel);
        $this->assertSame(5.0, $result->fee);
    }

    public function testProvinceRuleMatchesWhenNoDeeperRule(): void
    {
        $template = $this->baseTemplate();
        $rules = [
            $this->makeRule(id: 7, matchLevel: 1, regionIds: [1001], firstFee: 3.0),
        ];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            1,
        );

        $this->assertSame('rule', $result->source);
        $this->assertSame(7, $result->matchedRuleId);
        $this->assertSame(1, $result->matchedLevel);
    }

    public function testPieceFormulaWithContinueSteps(): void
    {
        // 首件 1 件 5 元；续 1 件 2 元；3 件 => 5 + ceil(2/1)*2 = 9
        $template = $this->baseTemplate();
        $rules = [$this->makeRule(
            id: 11,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 1.0,
            firstFee: 5.0,
            continueAmount: 1.0,
            continueFee: 2.0,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            3,
        );
        $this->assertSame(9.0, $result->fee);
    }

    public function testWeightFormulaCeilingRoundsUpPartialStep(): void
    {
        // 首 2kg 10 元；续 3kg 6 元；5.5kg => 10 + ceil(3.5/3)*6 = 10 + 12 = 22
        $template = $this->baseTemplate(['charge_type' => 'weight']);
        $rules = [$this->makeRule(
            id: 12,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 2.0,
            firstFee: 10.0,
            continueAmount: 3.0,
            continueFee: 6.0,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            5.5,
        );
        $this->assertSame(22.0, $result->fee);
    }

    public function testZeroContinueAmountFallsBackToFirstFeeOnly(): void
    {
        $template = $this->baseTemplate();
        $rules = [$this->makeRule(
            id: 13,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 1.0,
            firstFee: 8.0,
            continueAmount: 0.0,
            continueFee: 99.0,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            10,
        );
        // 续费被守护跳过，只收首费
        $this->assertSame(8.0, $result->fee);
    }

    public function testFeeCalculationUsesCentPrecisionForDecimalFees(): void
    {
        // 0.10 + ceil(2 / 1) * 0.20 = 0.50；费用部分必须按分计算，避免 float 累加误差。
        $template = $this->baseTemplate();
        $rules = [$this->makeRule(
            id: 15,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 1.0,
            firstFee: 0.10,
            continueAmount: 1.0,
            continueFee: 0.20,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            3,
        );

        $this->assertSame(0.5, $result->fee);
    }

    public function testCountBoundaryUsesFixedScalePrecision(): void
    {
        // float 下 0.1 + 0.2 可能略大于 0.3，阶梯边界必须仍按 0.3 处理，不能多收续费。
        $template = $this->baseTemplate();
        $rules = [$this->makeRule(
            id: 16,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 0.3,
            firstFee: 1.00,
            continueAmount: 0.1,
            continueFee: 1.00,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            0.1 + 0.2,
        );

        $this->assertSame(1.0, $result->fee);
    }

    public function testBelowFirstAmountOnlyChargesFirstFee(): void
    {
        $template = $this->baseTemplate();
        $rules = [$this->makeRule(
            id: 14,
            matchLevel: 4,
            regionIds: [4001],
            firstAmount: 3.0,
            firstFee: 5.0,
            continueAmount: 1.0,
            continueFee: 2.0,
        )];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            2,
        );
        $this->assertSame(5.0, $result->fee);
    }

    public function testNoMatchWhenRuleIdNotInPath(): void
    {
        $template = $this->baseTemplate();
        // 规则限上海，地址北京
        $rules = [$this->makeRule(id: 21, matchLevel: 1, regionIds: [1002], firstFee: 99.0)];
        $result = $this->service->calculateWithRules(
            $template,
            $rules,
            $this->beijingPath(),
            1,
        );
        $this->assertSame('default', $result->source);
        $this->assertSame(10.0, $result->fee);
    }

    public function testNegativeCountClampedToZero(): void
    {
        $template = $this->baseTemplate();
        $result = $this->service->calculateWithRules(
            $template,
            [],
            $this->beijingPath(),
            -5,
        );
        $this->assertSame(10.0, $result->fee); // 仍走 default_first_fee
    }

    private function beijingPath(): RegionPathDto
    {
        return new RegionPathDto(
            provinceId: 1001,
            cityId: 2001,
            districtId: 3001,
            streetId: 4001,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseTemplate(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => '默认模板',
            'charge_type' => 'piece',
            'default_first_amount' => 1.0,
            'default_first_fee' => 10.0,
            'default_continue_amount' => 1.0,
            'default_continue_fee' => 3.0,
            'status' => 1,
        ], $overrides);
    }

    /**
     * @param array<int, int> $regionIds
     * @return array<string, mixed>
     */
    private function makeRule(
        int $id,
        int $matchLevel,
        array $regionIds,
        float $firstAmount = 1.0,
        float $firstFee = 5.0,
        float $continueAmount = 1.0,
        float $continueFee = 2.0,
    ): array {
        return [
            'id' => $id,
            'template_id' => 1,
            'match_level' => $matchLevel,
            'region_ids' => $regionIds,
            'region_codes' => [],
            'region_names' => [],
            'region_path_texts' => [],
            'first_amount' => $firstAmount,
            'first_fee' => $firstFee,
            'continue_amount' => $continueAmount,
            'continue_fee' => $continueFee,
            'region_status' => 1,
            'region_invalid_reason' => null,
            'sort' => 0,
        ];
    }
}
