import type { Content } from "./types";

export const zh: Content = {
  meta: {
    title: "SiroCommerce — 面向 D2C、B2B 与多商户市场的自托管电商平台",
    description:
      "SiroCommerce 是面向 D2C 零售、B2B 批发和多商户市场的开源自托管电商软件。商品、结算、支付与客户数据都由你自己掌握。",
    keywords: [
      "自托管电商",
      "开源电商系统",
      "B2B 电商",
      "多商户平台",
      "无头电商",
      "越南支付",
      "SiroCommerce",
    ],
    ogLocale: "zh_CN",
    htmlLang: "zh-Hans",
  },
  ui: {
    skip: "跳到主要内容",
    menu: "菜单",
    close: "关闭",
    language: "语言",
    switchTo: "切换语言",
    currentLanguage: "简体中文",
    arrow: "→",
  },
  nav: [
    { label: "平台", href: "#platform" },
    { label: "功能", href: "#capabilities" },
    { label: "行业", href: "#industries" },
    { label: "运维", href: "#operations" },
    { label: "版本", href: "#editions" },
    { label: "联系我们", href: "#contact" },
  ],
  cta: {
    talk: "联系我们",
    features: "查看功能",
    emailSubject: "咨询 SiroCommerce",
    waitlistSubject: "SiroCommerce Pro 最新进展",
  },
  hero: {
    eyebrow: "开源 · 自托管 · API 优先",
    title: "真正属于你的电商软件。",
    lead:
      "SiroCommerce 用一个商务核心支撑 D2C 零售、B2B 批发和多商户业务。运行在你自己的基础设施上，接入自己的支付与物流服务商，订单、价格和客户数据始终掌握在你手中。",
    notes: ["无按单平台抽成", "运行在你自己的基础设施上", "18 个行业预设"],
    visual: {
      label: "店铺与结算页面预览",
      windowTitle: "yourshop.cn",
      storeName: "北田药房",
      nav: ["商店", "护理", "订阅"],
      heroTitle: "日常护理，送货到家。",
      heroCopy: "药师审核的日常护理，当日发货。",
      products: [
        { name: "每日免疫", price: "420,000 ₫", tone: "olive" },
        { name: "睡眠支持", price: "360,000 ₫", tone: "sand" },
        { name: "皮肤屏障", price: "540,000 ₫", tone: "clay" },
      ],
      cartTitle: "购物车",
      cartLines: [
        { name: "每日免疫", meta: "1 × 60 粒", price: "420,000 ₫" },
        { name: "睡眠支持", meta: "1 × 30 片", price: "360,000 ₫" },
      ],
      cartTotal: "780,000 ₫",
      checkout: "去结算",
      cartNote: "税费将在结算时计算。",
      foot: "满 500,000 ₫ 免运费 · 30 天退货",
    },
  },
  proof: [
    { value: "18", label: "个行业预设，一键应用" },
    { value: "6", label: "种可接入的支付方式" },
    { value: "1", label: "个核心支撑 D2C、B2B 与多商户" },
  ],
  platform: {
    eyebrow: "一个核心，三种卖法",
    title: "从一个业务模式开始，无需更换平台即可扩展。",
    lead:
      "商品、价格、结算、订单与履约共用一套核心。渠道与策略按店铺变化，因此批发客户与零售客户可以共用商品库而互不干扰。",
    cards: [
      {
        no: "01",
        title: "D2C 零售",
        copy: "快速、编辑风格的店铺，支持游客结算、账户、促销与订阅。",
        points: ["18 个行业预设", "游客与账户结算", "优惠券、礼品卡、店铺余额"],
        icon: "bag",
      },
      {
        no: "02",
        title: "B2B 与批发",
        copy: "报价、审批与合同价格，与零售订单走同一条处理链路。",
        points: ["企业账户与采购人", "价格表与协商价", "报价转订单审批"],
        icon: "building",
      },
      {
        no: "03",
        title: "轻量多商户",
        copy: "先引入第三方卖家，需要时再升级到完整多商户方案。",
        points: ["卖家商品与可见性", "按单佣金快照", "结算台账与银行导出"],
        icon: "store",
      },
    ],
  },
  capabilities: {
    eyebrow: "开箱即用",
    title: "无需堆叠插件，也有足够深度。",
    lead: "购物车、税费、订阅和报表通常要拼多个插件，现在由核心统一承担。",
    items: [
      { icon: "box", title: "商品与媒体", copy: "多规格、多仓库存、WebP/AVIF 派生图和可用于 CDN 的媒体 URL。", tag: "内置" },
      { icon: "card", title: "结算与支付", copy: "货到付款、银行转账、VNPay、MoMo、Stripe、PayPal。用你自己的凭证启用。", tag: "内置" },
      { icon: "truck", title: "物流与自提", copy: "GHN、Viettel Post、DHL 适配器，以及带自提点的线上下单到店取货。", tag: "内置" },
      { icon: "tag", title: "价格、税费与促销", copy: "B2B 价格表、税率区域、优惠券、礼品卡、店铺余额与会员优惠。", tag: "内置" },
      { icon: "repeat", title: "订阅", copy: "订阅计划、周期订单、续订处理与订阅者门户。", tag: "内置" },
      { icon: "store", title: "轻量多商户", copy: "卖家、佣金快照，以及可导出银行 CSV 的结算台账。", tag: "Lite" },
      { icon: "chart", title: "运维", copy: "健康检查、Prometheus 指标、备份演练、审计日志与同意管理。", tag: "内置" },
      { icon: "code", title: "无头 API", copy: "REST 与 OpenAPI、Webhook、前后端分离的店铺与管理后台。", tag: "内置" },
    ],
  },
  industries: {
    eyebrow: "接近完成的主题",
    title: "18 个行业预设，再改成你的样子。",
    lead:
      "在后台一键应用预设，然后调整颜色、字体、页头、页脚、商品卡和首页区块。不需要主题市场。",
    note: "预设只是起点，不是锁定的模板。每个店铺都可以克隆预设、保留自己的主题 key，并按渠道调整布局。",
    items: [
      "时尚",
      "美妆",
      "药房",
      "餐饮食品",
      "消费电子",
      "B2B",
      "超市",
      "护肤彩妆",
      "鞋履",
      "珠宝",
      "家具",
      "运动",
      "宠物",
      "母婴",
      "图书",
      "鲜花礼品",
      "营养补充",
      "汽车配件",
    ],
  },
  operations: {
    eyebrow: "为真正运营店铺的人而做",
    title: "像运维基础设施一样运营，而不是黑盒。",
    lead: "上线后你需要的端点、任务和记录都在：健康检查、指标、备份、审计与同意管理。",
    points: [
      "你自己的数据库与备份",
      "健康与就绪端点",
      "带重试的队列任务",
      "审计日志与隐私控制",
    ],
    panelTitle: "运维界面",
    panelTag: "内置",
    panelRows: [
      { label: "API 健康与就绪", value: "提供端点" },
      { label: "后台任务", value: "队列驱动" },
      { label: "备份与恢复", value: "提供手册" },
      { label: "指标", value: "Prometheus" },
      { label: "审计与同意", value: "内置" },
    ],
  },
  editions: {
    eyebrow: "开放核心",
    title: "免费开始，业务需要时再升级。",
    lead:
      "Community 是可自托管的完整商务核心。付费版是打包功能、授权与支持，不是另一套代码库。",
    community: {
      name: "Community",
      badge: "免费",
      price: "自托管",
      copy: "适合希望拥有整套技术栈、并有工程能力自行运营的团队。",
      points: ["完整商务核心", "18 个行业预设", "API 与店铺源码", "按自己的节奏升级"],
      cta: "联系我们",
    },
    pro: {
      name: "Pro",
      badge: "开发中",
      price: "授权",
      copy: "适合多店铺运营方，需要打包主题、治理与托管选项。",
      points: ["打包的多店铺主题", "授权与权限控制", "SSO、审计导出、托管 AI", "优先支持与 SLA"],
      cta: "获取 Pro 更新",
    },
    note: "Pro 尚未开售。最终范围与价格将在发布时公布；以上内容目前不会作为已交付的 Pro 功能出售。",
  },
  steps: {
    eyebrow: "开始使用",
    title: "从第一次沟通到可运营的店铺。",
    lead: "一个简短、偏技术的流程。没有藏在销售材料里的迁移意外。",
    items: [
      { no: "01", title: "确定范围", copy: "业务模式、商品规模、需要的服务商，以及你真正需要的上线时间。" },
      { no: "02", title: "部署", copy: "安装到你的基础设施，连接支付与物流凭证，迁移数据。" },
      { no: "03", title: "运营", copy: "培训团队、交付运维手册，并决定是否需要长期支持。" },
    ],
  },
  faq: {
    eyebrow: "常见问题",
    title: "团队在开始前会问什么。",
    lead: "面向技术与业务负责人的简短回答。",
    items: [
      {
        question: "SiroCommerce 免费吗？",
        answer:
          "Community 版可免费自托管，包含完整商务核心。Pro 与 Enterprise 正在开发中，将以打包功能、授权和支持的形式出售，而不是单独的代码分支。",
      },
      {
        question: "必须使用 SiroCommerce 的云服务吗？",
        answer:
          "不需要。SiroCommerce 安装在你掌控的基础设施上。你需要提供应用服务器、数据库、缓存和对象存储；这个营销站点是静态的，可以部署在 CDN 上。",
      },
      {
        question: "支持越南支付和物流吗？",
        answer:
          "支持。VNPay、MoMo、GHN 和 Viettel Post 集成已在代码中，用你的商户凭证启用。国际选项包括 Stripe、PayPal 和 DHL。",
      },
      {
        question: "这是 SaaS 产品吗？",
        answer:
          "不是。它是自托管软件。你的团队拥有部署、数据和升级。付费版在同一代码库上增加授权、打包功能和支持。",
      },
      {
        question: "D2C 和 B2B 能共用商品库吗？",
        answer:
          "可以。企业、价格表、报价和审批与零售结算并存。店铺与渠道范围可以在需要时隔离商品、价格和主题。",
      },
      {
        question: "Pro 包含什么？",
        answer:
          "计划包括：打包的多店铺主题、授权与权限控制、SSO/SAML、审计导出、托管 AI 选项、优先支持和 SLA。尚未开售，最终范围会在销售前公布。",
      },
    ],
  },
  contact: {
    eyebrow: "联系我们",
    title: "告诉我们你正在做什么。",
    lead: "发送业务模式、大致商品规模以及需要的服务商。我们会通过邮件回复下一步技术问题。",
    button: "给团队发邮件",
    note: "想快速通话？附上你的时区，我们会建议时间。",
  },
  footer: {
    tagline: "为希望拥有整套技术栈的团队提供自托管电商。",
    columns: [
      {
        title: "平台",
        links: [
          { label: "概览", href: "#platform" },
          { label: "功能", href: "#capabilities" },
          { label: "行业", href: "#industries" },
          { label: "运维", href: "#operations" },
        ],
      },
      {
        title: "版本",
        links: [
          { label: "Community", href: "#editions" },
          { label: "Pro（开发中）", href: "#editions" },
          { label: "常见问题", href: "#faq" },
        ],
      },
      {
        title: "联系",
        links: [
          { label: "邮件", href: "#contact" },
          { label: "联系我们", href: "#contact" },
        ],
      },
    ],
    rights: "© 2026 SiroCommerce. 保留所有权利。",
    madeIn: "Self-hosted commerce, made in Vietnam.",
  },
};
