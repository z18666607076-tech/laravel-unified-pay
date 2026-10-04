<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Fake;

use Freeman\UnifiedPay\Contracts\ProfitSharingGateway;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\DTO\ProfitShareResult;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Support\Values;

final class FakeWechatGateway extends FakeGateway implements ProfitSharingGateway
{
    /** @var array<string, ProfitShareResult> */
    private array $shares = [];

    public function __construct(Recorder $recorder)
    {
        parent::__construct(Channel::Wechat->value, $recorder);
    }

    public function addProfitShareReceiver(string $type, string $account, string $relationType, ?string $name = null): void
    {
        if ($type === '' || $account === '' || $relationType === '') {
            throw new PaymentException('Profit sharing receiver is incomplete.', 'wechat', null, 422);
        }

        $this->recorder->profitShareReceivers[] = [
            'channel' => $this->channel(),
            'type' => $type,
            'account' => $account,
        ];
    }

    public function profitShare(ProfitShareRequest $request): ProfitShareResult
    {
        if ($request->receivers === []) {
            throw new PaymentException('Profit sharing needs at least one receiver.', 'wechat', null, 422);
        }

        $outOrderNo = Values::reference($request->outOrderNo, 'out_order_no', 'wechat');
        $this->recorder->profitShares[] = ['channel' => $this->channel(), 'request' => $request];
        $result = new ProfitShareResult(
            channel: $this->channel(),
            outOrderNo: $outOrderNo,
            orderId: 'ps_fake_'.$outOrderNo,
            state: 'PROCESSING',
            transactionId: $request->transactionId,
        );
        $this->shares[$outOrderNo] = $result;

        return $result;
    }

    public function queryProfitShare(string $outOrderNo, string $transactionId): ProfitShareResult
    {
        $outOrderNo = Values::reference($outOrderNo, 'out_order_no', 'wechat');
        $existing = $this->shares[$outOrderNo] ?? null;

        if ($existing === null) {
            throw new PaymentException('Fake profit-sharing order ['.$outOrderNo.'] was not created.', 'wechat', 'resource_missing', 404);
        }

        return new ProfitShareResult(
            channel: $this->channel(),
            outOrderNo: $outOrderNo,
            orderId: $existing->orderId,
            state: 'FINISHED',
            transactionId: $transactionId,
        );
    }
}
