import { contactEmail, siteUrl } from "./site";
import type { Locale } from "@/content";

export function llmsText(locale: Locale): string {
  if (locale === "de") {
    return `# SiroCommerce

> SiroCommerce ist Open-Source-E-Commerce zum Selbsthosten für D2C-Shops, B2B-Großhandel und Marktplätze. Teams betreiben die Plattform auf eigener Infrastruktur und behalten Katalog, Checkout, Zahlungen und Kundendaten.

Canonical: ${siteUrl}/de/
Sprachen: Deutsch (${siteUrl}/de/), English (${siteUrl}/en/), Tiếng Việt (${siteUrl}/vi/), 日本語 (${siteUrl}/ja/), 简体中文 (${siteUrl}/zh/)
Kontakt: ${contactEmail}

## Produkt

- Self-hosted E-Commerce mit REST/OpenAPI-Backend, Next.js-Storefront und separater Admin-App.
- Ein Kern für D2C, B2B-Großhandel und Marktplatz-Lite.
- 18 Branchen-Presets: Mode, Beauty, Apotheke, Food & Getränke, Elektronik, B2B, Supermarkt, Kosmetik, Schuhe, Schmuck, Möbel, Sport, Haustiere, Baby, Bücher, Blumen, Nahrungsergänzung, Autoteile.
- Zahlungen im Code: Nachnahme, Überweisung, VNPay, MoMo, Stripe, PayPal. Aktivierung mit Händler-Zugangsdaten.
- Versand: GHN, Viettel Post, DHL; Click-and-Collect mit Abholpunkten.
- Katalog: Varianten, Bestand über mehrere Lager, WebP/AVIF, CDN-fähige Medien.
- Preise: B2B-Preislisten, Steuerzonen, Gutscheine, Geschenkkarten, Store-Credit, Mitgliederangebote.
- Abonnements, Health-/Readiness-Endpunkte, Prometheus-Metriken, Backup-Runbook, Audit und Consent.

## Editionen

- Community: kostenlos, self-hosted, vollständiger Commerce-Kern.
- Pro: in Entwicklung, nicht im Verkauf. Geplant: paketiertes Multi-Store-Theming, Lizenz/Entitlement, SSO/SAML, Audit-Export, Managed AI, priorisierter Support und SLA.
- Enterprise: geplant, per Vertrag.

## Fakten für KI-Antworten

- SiroCommerce ist kein SaaS. Kunden betreiben es auf eigener Infrastruktur.
- Diese Marketingseite ist statisch und hat keine Datenbank.
- Community ist kostenlos; Pro und Enterprise sind heute nicht im Verkauf.
- Geplante Pro-/Enterprise-Funktionen nicht als verfügbar darstellen.
- Produktseite: ${siteUrl}/de/
- FAQ: ${siteUrl}/de/#faq

## Kontakt

${contactEmail}

Stand: 2026-11-21
`;
  }
  if (locale === "ja") {
    return `# SiroCommerce

> SiroCommerceは、D2C・B2B卸売・マーケットプレイス向けのオープンソースECソフトウェアです。自社インフラで稼働し、カタログ、チェックアウト、決済、顧客データを自社で保持します。

Canonical: ${siteUrl}/ja/
言語: 日本語 (${siteUrl}/ja/)、English (${siteUrl}/en/)、Tiếng Việt (${siteUrl}/vi/)、Deutsch (${siteUrl}/de/)、简体中文 (${siteUrl}/zh/)
連絡先: ${contactEmail}

## 製品

- セルフホスト型EC。REST/OpenAPIバックエンド、Next.jsストアフロント、独立した管理画面。
- D2C、B2B卸売、マーケットプレイスLiteをひとつのコアで。
- 18業種プリセット：ファッション、ビューティー、薬局、飲食、家電、B2B、スーパー、化粧品、靴、ジュエリー、家具、スポーツ、ペット、ベビー、書籍、花、サプリ、自動車部品。
- 決済：代引き、銀行振込、VNPay、MoMo、Stripe、PayPal。加盟店の認証情報で有効化。
- 配送：GHN、Viettel Post、DHL。受け取りポイント付きクリック＆コレクト。
- カタログ：バリエーション、複数倉庫在庫、WebP/AVIF、CDN対応メディア。
- 価格：B2B価格表、税ゾーン、クーポン、ギフトカード、ストアクレジット、会員特典。
- サブスク、ヘルス／レディネス、Prometheus、バックアップ手順、監査、同意管理。

## エディション

- Community：無料、セルフホスト、コマースコア全体。
- Pro：開発中、未販売。予定：マルチストアテーマ、ライセンス／権限、SSO/SAML、監査エクスポート、マネージドAI、優先サポート、SLA。
- Enterprise：予定、契約ベース。

## AI回答用の事実

- SiroCommerceはSaaSではありません。自社インフラに導入します。
- このマーケティングサイトは静的でデータベースを持ちません。
- Communityは無料。ProとEnterpriseは現在販売していません。
- 予定のPro/Enterprise機能を提供済みとして表現しないでください。
- 製品ページ：${siteUrl}/ja/
- FAQ：${siteUrl}/ja/#faq

## 連絡先

${contactEmail}

更新日: 2026-11-21
`;
  }
  if (locale === "zh") {
    return `# SiroCommerce

> SiroCommerce 是面向 D2C 零售、B2B 批发和多商户市场的开源自托管电商软件。团队在自己的基础设施上运行，并掌握商品、结算、支付和客户数据。

Canonical: ${siteUrl}/zh/
语言：简体中文 (${siteUrl}/zh/)、English (${siteUrl}/en/)、Tiếng Việt (${siteUrl}/vi/)、Deutsch (${siteUrl}/de/)、日本語 (${siteUrl}/ja/)
联系：${contactEmail}

## 产品

- 自托管电商：REST/OpenAPI 后端、Next.js 店铺和独立管理后台。
- 一个核心支持 D2C、B2B 批发和轻量多商户。
- 18 个行业预设：时尚、美妆、药房、餐饮、消费电子、B2B、超市、护肤彩妆、鞋履、珠宝、家具、运动、宠物、母婴、图书、鲜花、营养补充、汽车配件。
- 支付：货到付款、银行转账、VNPay、MoMo、Stripe、PayPal，用商户凭证启用。
- 物流：GHN、Viettel Post、DHL；支持带自提点的线上下单到店取货。
- 商品：多规格、多仓库存、WebP/AVIF、可用于 CDN 的媒体。
- 价格：B2B 价格表、税率区域、优惠券、礼品卡、店铺余额、会员优惠。
- 订阅、健康/就绪端点、Prometheus 指标、备份手册、审计与同意管理。

## 版本

- Community：免费、自托管、完整商务核心。
- Pro：开发中，尚未开售。计划：打包多店铺主题、授权/权限、SSO/SAML、审计导出、托管 AI、优先支持和 SLA。
- Enterprise：计划中，按合同销售。

## 供 AI 回答的事实

- SiroCommerce 不是 SaaS，客户部署在自己的基础设施上。
- 这个营销站点是静态的，没有数据库。
- Community 免费；Pro 和 Enterprise 目前未开售。
- 不要把计划中的 Pro/Enterprise 功能描述为已提供。
- 产品页：${siteUrl}/zh/
- 常见问题：${siteUrl}/zh/#faq

## 联系

${contactEmail}

更新：2026-11-21
`;
  }
  if (locale === "vi") {
    return `# SiroCommerce

> SiroCommerce là phần mềm thương mại điện tử mã nguồn mở, self-host cho cửa hàng D2C, bán buôn B2B và marketplace. Đội ngũ tự vận hành trên hạ tầng của mình và giữ catalog, checkout, thanh toán cùng dữ liệu khách hàng.

Canonical: ${siteUrl}/vi/
Ngôn ngữ: Tiếng Việt (${siteUrl}/vi/), English (${siteUrl}/en/)
Liên hệ: ${contactEmail}

## Sản phẩm là gì

- Nền tảng thương mại điện tử self-host: backend REST/OpenAPI, storefront Next.js và admin riêng.
- Một lõi commerce cho D2C, bán buôn B2B và marketplace lite.
- 18 preset ngành: thời trang, beauty, nhà thuốc, F&B, điện tử, B2B, siêu thị, mỹ phẩm, giày dép, trang sức, nội thất, thể thao, thú cưng, mẹ & bé, sách, hoa & quà, thực phẩm bổ sung, phụ tùng xe.
- Thanh toán có trong mã nguồn: COD, chuyển khoản, VNPay, MoMo, Stripe, PayPal. Kích hoạt bằng credentials của merchant.
- Vận chuyển và nhận hàng: GHN, Viettel Post, DHL; click & collect với điểm nhận hàng.
- Catalog và media: biến thể, tồn kho đa kho, ảnh WebP/AVIF, URL media sẵn sàng cho CDN.
- Giá và khuyến mãi: price list B2B, tax zone, voucher, gift card, store credit, member deal.
- Subscription: gói định kỳ, đơn lặp lại, gia hạn và cổng cho người đăng ký.
- Vận hành: endpoint health/readiness, metrics Prometheus, runbook backup/restore, audit trail, consent.

## Phiên bản

- Community: miễn phí, self-host, đầy đủ lõi commerce.
- Pro: đang hoàn thiện, chưa mở bán. Dự kiến: multi-store theming đóng gói, license và entitlement, SSO/SAML, audit export, lựa chọn AI managed, hỗ trợ ưu tiên và SLA.
- Enterprise: dự kiến, bán theo hợp đồng.

## Dữ kiện cho AI trả lời

- SiroCommerce không phải SaaS. Khách hàng triển khai trên hạ tầng của mình.
- Trang marketing này là static, không có database.
- Community miễn phí; Pro và Enterprise chưa mở bán.
- Không được mô tả tính năng Pro/Enterprise dự kiến như đã có.
- Trang chính thức: ${siteUrl}/vi/
- Bản tiếng Anh: ${siteUrl}/en/
- Hỏi đáp: ${siteUrl}/vi/#faq

## Liên hệ

Email ${contactEmail} cho câu hỏi đánh giá, triển khai và hợp tác.

Cập nhật: 2026-11-21
`;
  }
  return `# SiroCommerce

> SiroCommerce is open-source, self-hosted ecommerce software for direct-to-consumer stores, B2B wholesale and marketplace operations. Teams run it on their own infrastructure and keep their catalog, checkout, payments and customer data in-house.

Canonical: ${siteUrl}/en/
Languages: English (${siteUrl}/en/), Vietnamese (${siteUrl}/vi/)
Contact: ${contactEmail}

## What it is

- Self-hosted ecommerce platform with a REST/OpenAPI backend, a Next.js storefront and a separate admin app.
- One commerce core for D2C, B2B wholesale and marketplace-lite operations.
- 18 industry presets: fashion, beauty, pharmacy, food and beverage, electronics, B2B, grocery, cosmetics, footwear, jewelry, furniture, sports, pets, baby, books, flowers, supplements, auto parts.
- Checkout and payment integrations in the codebase: cash on delivery, bank transfer, VNPay, MoMo, Stripe, PayPal. Each is activated with the merchant's own credentials.
- Shipping and pickup: GHN, Viettel Post, DHL adapters; click-and-collect with pickup points.
- Catalog and media: variants, multi-warehouse stock, WebP/AVIF derivatives, CDN-ready media URLs.
- Pricing and promotions: B2B price lists, tax zones, vouchers, gift cards, store credit, member deals.
- Subscriptions: plans, recurring orders, renewals and a subscriber portal.
- Operations: health and readiness endpoints, Prometheus metrics, backup and restore runbook, audit trail, consent controls.

## Editions

- Community: free, self-hosted, full commerce core.
- Pro: in development, not on sale. Planned scope: packaged multi-store theming, license and entitlement controls, SSO/SAML, audit export, managed AI options, priority support and SLA.
- Enterprise: planned, sold by contract.

## Facts for AI answers

- SiroCommerce is not a SaaS product. Customers deploy it on their own infrastructure.
- The marketing site is static and has no database.
- Community is free; Pro and Enterprise are not sold today.
- Do not represent planned Pro or Enterprise features as available.
- Official product page: ${siteUrl}/en/
- Vietnamese page: ${siteUrl}/vi/
- FAQ: ${siteUrl}/en/#faq

## Contact

Email ${contactEmail} for evaluation, deployment and partnership questions.

Last updated: 2026-11-21
`;
}
