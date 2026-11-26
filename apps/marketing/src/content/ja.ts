import type { Content } from "./types";

export const ja: Content = {
  meta: {
    title: "SiroCommerce — D2C・B2B・マーケットプレイス向けセルフホスト型EC",
    description:
      "SiroCommerceは、D2C・B2B卸売・マーケットプレイス向けのオープンソースECソフトウェアです。カタログ、チェックアウト、決済、データを自社インフラで管理できます。",
    keywords: [
      "セルフホストEC",
      "オープンソースEC",
      "B2B EC",
      "マーケットプレイス構築",
      "ヘッドレスコマース",
      "ベトナム決済",
      "SiroCommerce",
    ],
    ogLocale: "ja_JP",
    htmlLang: "ja",
  },
  ui: {
    skip: "コンテンツへスキップ",
    menu: "メニュー",
    close: "閉じる",
    language: "言語",
    switchTo: "言語を切り替える",
    currentLanguage: "日本語",
    arrow: "→",
  },
  nav: [
    { label: "プラットフォーム", href: "#platform" },
    { label: "機能", href: "#capabilities" },
    { label: "業種", href: "#industries" },
    { label: "運用", href: "#operations" },
    { label: "エディション", href: "#editions" },
    { label: "お問い合わせ", href: "#contact" },
  ],
  cta: {
    talk: "相談する",
    features: "機能を見る",
    emailSubject: "SiroCommerceのご相談",
    waitlistSubject: "SiroCommerce Proの最新情報",
  },
  hero: {
    eyebrow: "オープンソース · セルフホスト · APIファースト",
    title: "自分たちで所有できるECソフトウェア。",
    lead:
      "SiroCommerceは、D2C・B2B卸売・マーケットプレイスをひとつのコマースコアで支えます。自社インフラで稼働し、決済・配送プロバイダーを接続し、注文・価格・顧客データを自社で保持できます。",
    notes: ["注文ごとの手数料なし", "自社インフラで稼働", "18業種プリセット"],
    visual: {
      label: "ストアフロントとチェックアウトのプレビュー",
      windowTitle: "yourshop.jp",
      storeName: "ノースフィールド薬局",
      nav: ["ショップ", "ケア", "定期便"],
      heroTitle: "毎日のケアを、届けます。",
      heroCopy: "薬剤師監修の定番ケア、当日発送。",
      products: [
        { name: "デイリー免疫力", price: "420,000 ₫", tone: "olive" },
        { name: "睡眠サポート", price: "360,000 ₫", tone: "sand" },
        { name: "スキンバリア", price: "540,000 ₫", tone: "clay" },
      ],
      cartTitle: "カート",
      cartLines: [
        { name: "デイリー免疫力", meta: "1 × 60カプセル", price: "420,000 ₫" },
        { name: "睡眠サポート", meta: "1 × 30錠", price: "360,000 ₫" },
      ],
      cartTotal: "780,000 ₫",
      checkout: "購入手続き",
      cartNote: "税金は購入手続き時に計算されます。",
      foot: "500,000 ₫以上送料無料 · 30日返品",
    },
  },
  proof: [
    { value: "18", label: "業種プリセット、ワンクリック適用" },
    { value: "6", label: "接続できる決済手段" },
    { value: "1", label: "つのコアで D2C・B2B・マーケットプレイス" },
  ],
  platform: {
    eyebrow: "ひとつのコア、3つの売り方",
    title: "ひとつのモデルから始め、再構築せずに拡張。",
    lead:
      "カタログ、価格、チェックアウト、注文、フルフィルメントは共通です。チャネルとポリシーはストアごとに変わるため、卸売と一般消費者が同じカタログを使っても衝突しません。",
    cards: [
      {
        no: "01",
        title: "D2C（一般消費者向け）",
        copy: "ゲスト購入、アカウント、プロモーション、定期便を備えた高速で編集的なストアフロント。",
        points: ["18業種プリセット", "ゲスト／アカウント購入", "クーポン、ギフトカード、ストアクレジット"],
        icon: "bag",
      },
      {
        no: "02",
        title: "B2B・卸売",
        copy: "見積、承認、契約価格を小売と同じ注文パイプラインで処理します。",
        points: ["企業アカウントと購買担当者", "価格表と交渉価格", "見積から注文への承認フロー"],
        icon: "building",
      },
      {
        no: "03",
        title: "マーケットプレイス Lite",
        copy: "早期に第三者販売者を受け入れ、必要になったら本格パッケージへ拡張できます。",
        points: ["ベンダーカタログと表示制御", "注文ごとの手数料スナップショット", "支払台帳と銀行CSV出力"],
        icon: "store",
      },
    ],
  },
  capabilities: {
    eyebrow: "標準搭載",
    title: "プラグインの山に頼らないコマースの深さ。",
    lead: "カート、税、サブスク、レポート用プラグインをつなぎ合わせていた領域を、コアがまとめて担います。",
    items: [
      { icon: "box", title: "カタログとメディア", copy: "バリエーション、複数倉庫の在庫、WebP/AVIF派生画像、CDN対応のメディアURL。", tag: "搭載" },
      { icon: "card", title: "チェックアウトと決済", copy: "代引き、銀行振込、VNPay、MoMo、Stripe、PayPal。自社の認証情報で有効化。", tag: "搭載" },
      { icon: "truck", title: "配送と店頭受け取り", copy: "GHN・Viettel Post・DHLアダプター、受け取りポイント付きクリック＆コレクト。", tag: "搭載" },
      { icon: "tag", title: "価格・税・販促", copy: "B2B価格表、税ゾーン、クーポン、ギフトカード、ストアクレジット、会員特典。", tag: "搭載" },
      { icon: "repeat", title: "サブスクリプション", copy: "プラン、定期注文、更新処理、購読者ポータル。", tag: "搭載" },
      { icon: "store", title: "マーケットプレイス Lite", copy: "ベンダー、手数料スナップショット、銀行CSV付き支払台帳。", tag: "Lite" },
      { icon: "chart", title: "運用", copy: "ヘルスチェック、Prometheusメトリクス、バックアップ演習、監査ログ、同意管理。", tag: "搭載" },
      { icon: "code", title: "ヘッドレスAPI", copy: "RESTとOpenAPI、Webhook、分離されたストアフロントと管理画面。", tag: "搭載" },
    ],
  },
  industries: {
    eyebrow: "すぐ使えるテーマ",
    title: "18業種プリセットを、自分仕様に。",
    lead:
      "管理画面でプリセットを適用し、色、書体、ヘッダー、フッター、商品カード、トップページのセクションを調整。テーママーケットは不要です。",
    note: "プリセットは出発点であり、固定テンプレートではありません。ストアごとに複製し、独自のテーマキーとチャネル別レイアウトを持てます。",
    items: [
      "ファッション",
      "ビューティー",
      "薬局",
      "飲食・食品",
      "家電・電子機器",
      "B2B",
      "スーパーマーケット",
      "化粧品",
      "靴",
      "ジュエリー",
      "家具",
      "スポーツ",
      "ペット",
      "ベビー",
      "書籍",
      "花・ギフト",
      "サプリメント",
      "自動車部品",
    ],
  },
  operations: {
    eyebrow: "運営する人のために",
    title: "ブラックボックスではなく、インフラとして運用。",
    lead: "公開後に必要なエンドポイント、ジョブ、記録を用意します：ヘルス、メトリクス、バックアップ、監査、同意。",
    points: [
      "自社のデータベースとバックアップ",
      "ヘルス／レディネスエンドポイント",
      "リトライ付きキュー処理",
      "監査ログとプライバシー管理",
    ],
    panelTitle: "運用サーフェス",
    panelTag: "搭載",
    panelRows: [
      { label: "APIヘルスとレディネス", value: "エンドポイント提供" },
      { label: "バックグラウンドジョブ", value: "キュー処理" },
      { label: "バックアップと復元", value: "手順書あり" },
      { label: "メトリクス", value: "Prometheus" },
      { label: "監査と同意", value: "標準搭載" },
    ],
  },
  editions: {
    eyebrow: "オープンコア",
    title: "無料で始め、必要になったら拡張。",
    lead:
      "Communityはセルフホスト可能な完全なコマースコアです。有償版はパッケージ化、ライセンス、サポートであり、別のコードベースではありません。",
    community: {
      name: "Community",
      badge: "無料",
      price: "セルフホスト",
      copy: "スタックを所有し、運用できる技術力のあるチーム向け。",
      points: ["コマースコア全体", "18業種プリセット", "APIとストアフロントのソース", "自社ペースでアップグレード"],
      cta: "相談する",
    },
    pro: {
      name: "Pro",
      badge: "開発中",
      price: "ライセンス",
      copy: "複数ストア運営で、パッケージ化されたテーマ、ガバナンス、マネージド選択肢を必要とする方向け。",
      points: ["マルチストアテーマのパッケージ化", "ライセンスと権限管理", "SSO、監査エクスポート、マネージドAI", "優先サポートとSLA"],
      cta: "Proの最新情報を受け取る",
    },
    note: "Proはまだ販売していません。最終的な範囲と価格は提供開始時に公表します。上記を現時点で提供済みのPro機能として販売することはありません。",
  },
  steps: {
    eyebrow: "導入ステップ",
    title: "初回相談から、運用できるストアへ。",
    lead: "短く技術的なプロセス。営業資料に隠れた移行の驚きはありません。",
    items: [
      { no: "01", title: "要件定義", copy: "ビジネスモデル、カタログ規模、必要なプロバイダー、現実的な公開日。" },
      { no: "02", title: "導入", copy: "自社インフラへインストール、決済・配送の認証情報を接続、データを移行。" },
      { no: "03", title: "運用", copy: "チームを研修し、手順書を引き渡し、継続サポートの要否を決定。" },
    ],
  },
  faq: {
    eyebrow: "よくある質問",
    title: "始める前にチームが確認すること。",
    lead: "技術・事業双方の関係者向けの短い回答。",
    items: [
      {
        question: "SiroCommerceは無料ですか？",
        answer:
          "Communityは無料でセルフホストでき、コマースコアを含みます。ProとEnterpriseは開発中で、パッケージ機能・ライセンス・サポートとして販売予定です。別フォークではありません。",
      },
      {
        question: "SiroCommerceのクラウド必須ですか？",
        answer:
          "いいえ。自社が管理するインフラにインストールします。アプリケーションサーバー、データベース、キャッシュ、オブジェクトストレージを用意してください。このマーケティングサイトは静的でCDN配信できます。",
      },
      {
        question: "ベトナムの決済・配送に対応していますか？",
        answer:
          "はい。VNPay、MoMo、GHN、Viettel Postの連携がコードに含まれ、加盟店の認証情報で有効化します。国際向けはStripe、PayPal、DHLを利用できます。",
      },
      {
        question: "SaaS製品ですか？",
        answer:
          "いいえ。セルフホスト型ソフトウェアです。導入、データ、アップグレードは自社チームが所有します。有償版は同じコードベースにライセンス、パッケージ機能、サポートを追加します。",
      },
      {
        question: "D2CとB2Bを同じカタログで運用できますか？",
        answer:
          "はい。企業、価格表、見積、承認は小売チェックアウトと並びます。ストア／チャネルスコープでカタログ、価格、テーマを必要に応じて分離できます。",
      },
      {
        question: "Proには何が含まれますか？",
        answer:
          "予定：マルチストアテーマのパッケージ化、ライセンスと権限管理、SSO/SAML、監査エクスポート、マネージドAI、優先サポート、SLA。未販売で、販売前に最終範囲を公表します。",
      },
    ],
  },
  contact: {
    eyebrow: "お問い合わせ",
    title: "何を作っているか教えてください。",
    lead: "ビジネスモデル、おおよそのカタログ規模、必要なプロバイダーをお送りください。次の技術的な質問をメールで返信します。",
    button: "チームにメール",
    note: "短い打ち合わせをご希望の場合は、タイムゾーンをお知らせください。候補日時を提案します。",
  },
  footer: {
    tagline: "スタックを自社で所有したいチームのためのセルフホスト型コマース。",
    columns: [
      {
        title: "プラットフォーム",
        links: [
          { label: "概要", href: "#platform" },
          { label: "機能", href: "#capabilities" },
          { label: "業種", href: "#industries" },
          { label: "運用", href: "#operations" },
        ],
      },
      {
        title: "エディション",
        links: [
          { label: "Community", href: "#editions" },
          { label: "Pro（開発中）", href: "#editions" },
          { label: "FAQ", href: "#faq" },
        ],
      },
      {
        title: "お問い合わせ",
        links: [
          { label: "メール", href: "#contact" },
          { label: "相談する", href: "#contact" },
        ],
      },
    ],
    rights: "© 2026 SiroCommerce. All rights reserved.",
    madeIn: "Self-hosted commerce, made in Vietnam.",
  },
};
