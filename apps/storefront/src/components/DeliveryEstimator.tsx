"use client";

import { useEffect, useState } from "react";
import { fill } from "@/lib/i18n";
import { formatMinor } from "@/lib/money";
import { useLocale } from "@/lib/i18n-client";
import { requestShippingEstimate, type ShippingEstimate, type ShippingMethod } from "@/lib/shipping-estimate";

type DeliveryArea = { name: string; districts: string[] };
type EstimatorLabels = {
  title: string;
  province: string;
  district: string;
  selectProvince: string;
  selectDistrict: string;
  prompt: string;
  free: string;
  fee: string;
  eta: string;
  freeOver: string;
  checkout: string;
  disclaimer: string;
  live: string;
  estimated: string;
  needsVariant: string;
  fallbackReason: string;
  loading: string;
  validationError: string;
  networkError: string;
  unavailableError: string;
  retry: string;
  freeMethod: string;
  etaDays: string;
  etaDay: string;
  liveDisclaimer: string;
};

type Status = "idle" | "loading" | "success" | "validation-error" | "error";

const deliveryAreas: DeliveryArea[] = [
  { name: "Hà Nội", districts: ["Ba Đình", "Hoàn Kiếm", "Cầu Giấy", "Đống Đa", "Hai Bà Trưng", "Long Biên"] },
  { name: "TP. Hồ Chí Minh", districts: ["Quận 1", "Quận 3", "Quận 7", "Bình Thạnh", "Phú Nhuận", "Thủ Đức"] },
  { name: "Đà Nẵng", districts: ["Hải Châu", "Thanh Khê", "Sơn Trà", "Ngũ Hành Sơn", "Liên Chiểu"] },
  { name: "Hải Phòng", districts: ["Hồng Bàng", "Lê Chân", "Ngô Quyền", "Kiến An", "Hải An"] },
  { name: "Cần Thơ", districts: ["Ninh Kiều", "Bình Thủy", "Cái Răng", "Ô Môn", "Thốt Nốt"] },
  { name: "Huế", districts: ["Thuận Hóa", "Phú Xuân", "Hương Thủy", "Hương Trà"] },
  { name: "Đồng Nai", districts: ["Biên Hòa", "Long Khánh", "Nhơn Trạch", "Trảng Bom"] },
  { name: "Quảng Ninh", districts: ["Hạ Long", "Cẩm Phả", "Uông Bí", "Móng Cái"] },
];

const labelsByLocale: Record<string, EstimatorLabels> = {
  vi: {
    title: "Ước tính giao hàng",
    province: "Tỉnh/Thành phố",
    district: "Quận/Huyện",
    selectProvince: "Chọn tỉnh/thành",
    selectDistrict: "Chọn quận/huyện",
    prompt: "Chọn tỉnh/thành và quận/huyện để xem ước tính.",
    free: "Miễn phí vận chuyển (ước tính)",
    fee: "Phí vận chuyển ước tính: {fee}",
    eta: "Thời gian giao dự kiến: {eta} (ước tính)",
    freeOver: "Miễn phí vận chuyển cho đơn từ {threshold}.",
    checkout: "Tính phí ở bước thanh toán.",
    disclaimer: "Đây là ước tính. Phí và thời gian chính xác được xác nhận ở bước thanh toán.",
    live: "Giá thật",
    estimated: "Ước tính",
    needsVariant: "Chọn biến thể để xem phí vận chuyển chính xác.",
    fallbackReason: "Đang dùng bảng giá mặc định.",
    loading: "Đang tính phí vận chuyển…",
    validationError: "Thông tin giao hàng chưa hợp lệ. Vui lòng chọn lại tỉnh/thành và quận/huyện.",
    networkError: "Không thể kết nối dịch vụ vận chuyển.",
    unavailableError: "Tạm thời chưa có phí vận chuyển cho khu vực này.",
    retry: "Thử lại",
    freeMethod: "Miễn phí",
    etaDays: "{min}–{max} ngày",
    etaDay: "{days} ngày",
    liveDisclaimer: "Giá và thời gian do đơn vị vận chuyển cung cấp.",
  },
  en: {
    title: "Delivery estimate",
    province: "Province/City",
    district: "District",
    selectProvince: "Choose province/city",
    selectDistrict: "Choose district",
    prompt: "Choose a province/city and district to see an estimate.",
    free: "Free shipping (estimate)",
    fee: "Estimated shipping: {fee}",
    eta: "Estimated delivery: {eta}",
    freeOver: "Free shipping on orders from {threshold}.",
    checkout: "Shipping is calculated at checkout.",
    disclaimer: "This is an estimate. The exact fee and timing are confirmed at checkout.",
    live: "Live price",
    estimated: "Estimate",
    needsVariant: "Choose a variant to see accurate shipping.",
    fallbackReason: "Using the default rate card.",
    loading: "Calculating shipping…",
    validationError: "Those delivery details aren't valid. Please check your selection.",
    networkError: "Couldn't reach the shipping service.",
    unavailableError: "Shipping isn't available for this area right now.",
    retry: "Retry",
    freeMethod: "Free",
    etaDays: "{min}–{max} days",
    etaDay: "{days} days",
    liveDisclaimer: "Fee and timing are provided by the carrier.",
  },
  de: {
    title: "Lieferkostenschätzung",
    province: "Provinz/Stadt",
    district: "Bezirk",
    selectProvince: "Provinz/Stadt wählen",
    selectDistrict: "Bezirk wählen",
    prompt: "Wählen Sie Provinz/Stadt und Bezirk, um eine Schätzung zu sehen.",
    free: "Kostenloser Versand (Schätzung)",
    fee: "Geschätzte Versandkosten: {fee}",
    eta: "Geschätzte Lieferung: {eta}",
    freeOver: "Kostenloser Versand ab {threshold}.",
    checkout: "Der Versand wird an der Kasse berechnet.",
    disclaimer: "Dies ist eine Schätzung. Die genauen Kosten und Zeiten werden an der Kasse bestätigt.",
    live: "Live-Preis",
    estimated: "Schätzung",
    needsVariant: "Wählen Sie eine Variante, um genaue Versandkosten zu sehen.",
    fallbackReason: "Es wird die Standard-Preistabelle verwendet.",
    loading: "Versandkosten werden berechnet…",
    validationError: "Diese Lieferangaben sind ungültig. Bitte prüfen Sie Ihre Auswahl.",
    networkError: "Der Versanddienst konnte nicht erreicht werden.",
    unavailableError: "Für dieses Gebiet ist der Versand derzeit nicht verfügbar.",
    retry: "Erneut versuchen",
    freeMethod: "Kostenlos",
    etaDays: "{min}–{max} Tage",
    etaDay: "{days} Tage",
    liveDisclaimer: "Kosten und Zeiten werden vom Versanddienstleister bereitgestellt.",
  },
  zh: {
    title: "配送费用估算",
    province: "省/市",
    district: "区/县",
    selectProvince: "选择省/市",
    selectDistrict: "选择区/县",
    prompt: "请选择省/市和区/县以查看估算。",
    free: "免费配送（估算）",
    fee: "预计运费：{fee}",
    eta: "预计送达：{eta}",
    freeOver: "订单满 {threshold} 免运费。",
    checkout: "运费将在结账时计算。",
    disclaimer: "此为估算。确切费用和时间将在结账时确认。",
    live: "实时价格",
    estimated: "估算",
    needsVariant: "请选择规格以查看准确的运费。",
    fallbackReason: "正在使用默认费率表。",
    loading: "正在计算运费…",
    validationError: "配送信息无效，请重新选择。",
    networkError: "无法连接配送服务。",
    unavailableError: "该地区暂时无法配送。",
    retry: "重试",
    freeMethod: "免费",
    etaDays: "{min}–{max} 天",
    etaDay: "{days} 天",
    liveDisclaimer: "费用和时间由承运商提供。",
  },
  ja: {
    title: "配送料の目安",
    province: "都道府県/市",
    district: "区/市町村",
    selectProvince: "都道府県/市を選択",
    selectDistrict: "区/市町村を選択",
    prompt: "都道府県/市と区/市町村を選択すると目安が表示されます。",
    free: "送料無料（目安）",
    fee: "送料の目安：{fee}",
    eta: "お届け予定：{eta}",
    freeOver: "{threshold}以上のご注文で送料無料。",
    checkout: "送料は購入手続き時に計算されます。",
    disclaimer: "これは目安です。正確な送料とお届け日時は購入手続き時に確定します。",
    live: "実際の料金",
    estimated: "目安",
    needsVariant: "正確な送料を表示するにはバリエーションを選択してください。",
    fallbackReason: "既定の料金表を使用しています。",
    loading: "送料を計算しています…",
    validationError: "配送先情報が正しくありません。選択を確認してください。",
    networkError: "配送サービスに接続できませんでした。",
    unavailableError: "この地域への配送は現在ご利用いただけません。",
    retry: "再試行",
    freeMethod: "無料",
    etaDays: "{min}〜{max}日",
    etaDay: "{days}日",
    liveDisclaimer: "送料とお届け日時は配送業者が提供します。",
  },
};

const variantIdPattern = /^[A-Za-z0-9][A-Za-z0-9._~:-]{0,63}$/;

function parseMinor(value: string | undefined): number | undefined {
  if (!value) return undefined;
  const amount = Number(value);
  return Number.isSafeInteger(amount) && amount >= 0 ? amount : undefined;
}

function readDeliveryConfig(): { flatMinor?: number; freeOverMinor?: number; eta?: string } {
  const flatMinor = parseMinor(process.env.NEXT_PUBLIC_STOREFRONT_SHIPPING_FLAT_RATE_MINOR);
  const freeOverMinor = parseMinor(process.env.NEXT_PUBLIC_STOREFRONT_SHIPPING_FREE_OVER_MINOR);
  const etaValue = process.env.NEXT_PUBLIC_STOREFRONT_SHIPPING_ETA?.trim();
  return {
    ...(flatMinor !== undefined ? { flatMinor } : {}),
    ...(freeOverMinor !== undefined ? { freeOverMinor } : {}),
    ...(etaValue ? { eta: etaValue } : {}),
  };
}

function formatAmount(minor: number, currency: string, locale: string): string {
  try {
    return formatMinor(minor, currency, locale);
  } catch {
    return `${minor.toLocaleString(locale)} ${currency}`;
  }
}

function formatEta(method: ShippingMethod, labels: EstimatorLabels): string {
  const min = method.etaDaysMin;
  const max = method.etaDaysMax;
  if (min !== undefined && max !== undefined) return fill(labels.etaDays, { min, max });
  if (min !== undefined) return fill(labels.etaDay, { days: min });
  if (max !== undefined) return fill(labels.etaDay, { days: max });
  return "";
}

export function DeliveryEstimator({
  priceMinor,
  currency,
  variantId,
  productId,
  qty = 1,
}: {
  priceMinor: number;
  currency: string;
  variantId?: string;
  productId?: string;
  qty?: number;
}) {
  const locale = useLocale();
  const labels = labelsByLocale[locale] ?? labelsByLocale.en!;
  const [province, setProvince] = useState("");
  const [district, setDistrict] = useState("");
  const [status, setStatus] = useState<Status>("idle");
  const [errorKind, setErrorKind] = useState<"network" | "unavailable">("network");
  const [estimate, setEstimate] = useState<ShippingEstimate | null>(null);
  const [retryToken, setRetryToken] = useState(0);
  const districts = deliveryAreas.find((area) => area.name === province)?.districts ?? [];
  const config = readDeliveryConfig();
  const hasConfig = config.flatMinor !== undefined || config.freeOverMinor !== undefined || config.eta !== undefined;
  const selected = province !== "" && district !== "";
  const resolvedVariantId = variantId?.trim() || productId?.trim() || "";
  const usableVariantId = variantIdPattern.test(resolvedVariantId) ? resolvedVariantId : "";
  const loading = status === "loading";
  const qualifiesFree = config.freeOverMinor !== undefined && priceMinor >= config.freeOverMinor;
  const estimatedFee = qualifiesFree ? 0 : config.flatMinor;

  useEffect(() => {
    if (!usableVariantId || !selected) {
      setStatus("idle");
      setEstimate(null);
      return;
    }
    const controller = new AbortController();
    let active = true;
    setStatus("loading");
    setEstimate(null);
    const timer = setTimeout(() => {
      void (async () => {
        const result = await requestShippingEstimate(
          { variantId: usableVariantId, qty, destination: { country: "VN", region: province } },
          controller.signal,
        );
        if (!active || controller.signal.aborted) return;
        if (result.ok) {
          setEstimate(result.data);
          setStatus("success");
          return;
        }
        if (result.reason === "aborted") return;
        setEstimate(null);
        setErrorKind(result.reason === "network" ? "network" : "unavailable");
        setStatus(result.reason === "validation" ? "validation-error" : "error");
      })();
    }, 200);
    return () => {
      active = false;
      clearTimeout(timer);
      controller.abort();
    };
  }, [usableVariantId, province, district, selected, qty, retryToken]);

  const legacyEstimate = (showBadge: boolean) => <div className="sf-delivery-estimate">
    {showBadge && <span className="sf-delivery-badge sf-delivery-badge--estimated">{labels.estimated}</span>}
    {estimatedFee !== undefined ? <p>{estimatedFee === 0 ? labels.free : fill(labels.fee, { fee: formatAmount(estimatedFee, currency, locale) })}</p> : <p>{labels.checkout}</p>}
    {config.eta && <p>{fill(labels.eta, { eta: config.eta })}</p>}
    {config.freeOverMinor !== undefined && !qualifiesFree && <p className="muted">{fill(labels.freeOver, { threshold: formatAmount(config.freeOverMinor, currency, locale) })}</p>}
    <p className="sf-delivery-note">{labels.disclaimer}</p>
  </div>;

  let content;
  if (!usableVariantId) {
    content = !hasConfig
      ? <><p>{labels.checkout}</p><p className="sf-delivery-note">{labels.needsVariant}</p></>
      : !selected
        ? <p className="muted">{labels.prompt}</p>
        : <>{legacyEstimate(false)}<p className="sf-delivery-note">{labels.needsVariant}</p></>;
  } else if (status === "success" && estimate) {
    content = <div className="sf-delivery-estimate">
      <span className={`sf-delivery-badge sf-delivery-badge--${estimate.disclaimer}`}>
        {estimate.disclaimer === "live" ? labels.live : labels.estimated}
      </span>
      <ul className="sf-delivery-methods">
        {estimate.methods.map((method) => {
          const eta = formatEta(method, labels);
          return <li key={method.code} className="sf-delivery-method">
            <span className="sf-delivery-method-label">{method.label}</span>
            <span className="sf-delivery-method-price">
              {method.amountMinor === 0 ? labels.freeMethod : formatAmount(method.amountMinor, method.currency, locale)}
            </span>
            {eta && <span className="sf-delivery-method-eta">{eta}</span>}
          </li>;
        })}
      </ul>
      {estimate.fallbackReason && <p className="sf-delivery-fallback">{labels.fallbackReason}</p>}
      {estimate.freeShippingThresholdMinor !== null && priceMinor < estimate.freeShippingThresholdMinor && <p className="muted">
        {fill(labels.freeOver, { threshold: formatAmount(estimate.freeShippingThresholdMinor, estimate.currency, locale) })}
      </p>}
      <p className="sf-delivery-note">{estimate.disclaimer === "live" ? labels.liveDisclaimer : labels.disclaimer}</p>
    </div>;
  } else if (status === "loading") {
    content = <p className="sf-delivery-loading">{labels.loading}</p>;
  } else if (status === "validation-error") {
    content = <p className="sf-delivery-error">{labels.validationError}</p>;
  } else if (status === "error") {
    content = <div className="sf-delivery-fallback-block">
      {hasConfig ? legacyEstimate(true) : <p className="sf-delivery-error">{errorKind === "network" ? labels.networkError : labels.unavailableError}</p>}
      <button type="button" className="sf-delivery-retry secondary-button" disabled={loading} onClick={() => setRetryToken((token) => token + 1)}>
        {labels.retry}
      </button>
    </div>;
  } else {
    content = <p className="muted">{labels.prompt}</p>;
  }

  return <section className="sf-delivery" aria-labelledby="sf-delivery-title" aria-busy={loading}>
    <h2 id="sf-delivery-title">{labels.title}</h2>
    <div className="sf-delivery-fields">
      <label htmlFor="sf-delivery-province">
        <span>{labels.province}</span>
        <select id="sf-delivery-province" value={province} disabled={loading} onChange={(event) => { setProvince(event.target.value); setDistrict(""); }}>
          <option value="">{labels.selectProvince}</option>
          {deliveryAreas.map((area) => <option key={area.name} value={area.name}>{area.name}</option>)}
        </select>
      </label>
      <label htmlFor="sf-delivery-district">
        <span>{labels.district}</span>
        <select id="sf-delivery-district" value={district} disabled={loading || districts.length === 0} onChange={(event) => setDistrict(event.target.value)}>
          <option value="">{labels.selectDistrict}</option>
          {districts.map((name) => <option key={name} value={name}>{name}</option>)}
        </select>
      </label>
    </div>
    <div className="sf-delivery-result" role="status" aria-live="polite" aria-busy={loading}>
      {content}
    </div>
  </section>;
}
