import type { Content } from "./types";

export const vi: Content = {
  meta: {
    title: "SiroCommerce — Nền tảng thương mại điện tử self-host cho D2C, B2B và marketplace",
    description:
      "SiroCommerce là phần mềm thương mại điện tử mã nguồn mở, self-host cho cửa hàng D2C, bán buôn B2B và marketplace. Tự chủ catalog, checkout, thanh toán và dữ liệu.",
    keywords: [
      "thương mại điện tử self-host",
      "nền tảng bán hàng mã nguồn mở",
      "B2B commerce",
      "phần mềm marketplace",
      "headless commerce",
      "thanh toán VNPay MoMo",
      "SiroCommerce",
    ],
    ogLocale: "vi_VN",
    htmlLang: "vi",
  },
  ui: {
    skip: "Bỏ qua điều hướng",
    menu: "Menu",
    close: "Đóng",
    language: "Ngôn ngữ",
    switchTo: "Chuyển sang tiếng Anh",
    currentLanguage: "Tiếng Việt",
    arrow: "→",
  },
  nav: [
    { label: "Nền tảng", href: "#platform" },
    { label: "Năng lực", href: "#capabilities" },
    { label: "Ngành", href: "#industries" },
    { label: "Vận hành", href: "#operations" },
    { label: "Phiên bản", href: "#editions" },
    { label: "Liên hệ", href: "#contact" },
  ],
  cta: {
    talk: "Nhận tư vấn",
    features: "Xem năng lực",
    emailSubject: "Tư vấn SiroCommerce",
    waitlistSubject: "Cập nhật SiroCommerce Pro",
  },
  hero: {
    eyebrow: "Mã nguồn mở · Self-host · API-first",
    title: "Nền tảng thương mại điện tử để bạn thực sự sở hữu.",
    lead:
      "SiroCommerce là một lõi commerce cho cả bán lẻ D2C, bán buôn B2B và marketplace. Chạy trên hạ tầng của bạn, kết nối provider thanh toán và vận chuyển của bạn, giữ toàn bộ đơn hàng, giá và dữ liệu khách hàng trong tay bạn.",
    notes: ["Không phí theo đơn hàng", "Chạy trên hạ tầng của bạn", "18 preset ngành"],
    visual: {
      label: "Bản xem trước storefront và checkout",
      windowTitle: "cuahangcuaban.vn",
      storeName: "Nhà thuốc An Nhiên",
      nav: ["Cửa hàng", "Chăm sóc", "Định kỳ"],
      heroTitle: "Chăm sóc mỗi ngày, giao tận nơi.",
      heroCopy: "Sản phẩm được dược sĩ rà soát, gửi hàng trong ngày.",
      products: [
        { name: "Miễn dịch mỗi ngày", price: "420.000 ₫", tone: "olive" },
        { name: "Hỗ trợ giấc ngủ", price: "360.000 ₫", tone: "sand" },
        { name: "Phục hồi da", price: "540.000 ₫", tone: "clay" },
      ],
      cartTitle: "Giỏ hàng",
      cartLines: [
        { name: "Miễn dịch mỗi ngày", meta: "1 × 60 viên", price: "420.000 ₫" },
        { name: "Hỗ trợ giấc ngủ", meta: "1 × 30 viên", price: "360.000 ₫" },
      ],
      cartTotal: "780.000 ₫",
      checkout: "Thanh toán",
      cartNote: "Thuế tính ở bước thanh toán.",
      foot: "Miễn phí giao từ 500.000 ₫ · đổi trả 30 ngày",
    },
  },
  proof: [
    { value: "18", label: "preset ngành, áp dụng 1 click" },
    { value: "6", label: "cổng thanh toán sẵn sàng kết nối" },
    { value: "1", label: "lõi cho D2C, B2B và marketplace" },
  ],
  platform: {
    eyebrow: "Một lõi, ba cách bán",
    title: "Bắt đầu với một mô hình. Mở rộng mà không phải đổi nền tảng.",
    lead:
      "Catalog, giá, checkout, đơn hàng và fulfillment dùng chung. Channel và chính sách thay đổi theo từng store, nên khách sỉ và khách lẻ có thể chạy trên cùng catalog mà không giẫm chân nhau.",
    cards: [
      {
        no: "01",
        title: "Bán lẻ D2C",
        copy: "Storefront nhanh, giàu hình ảnh, có guest checkout, tài khoản, khuyến mãi và subscription.",
        points: ["18 preset ngành", "Guest và account checkout", "Voucher, gift card, store credit"],
        icon: "bag",
      },
      {
        no: "02",
        title: "B2B và bán buôn",
        copy: "Báo giá, phê duyệt và giá hợp đồng nằm trong cùng luồng đơn hàng với bán lẻ.",
        points: ["Tài khoản công ty và người mua", "Price list và giá thương lượng", "Quote chuyển thành đơn có phê duyệt"],
        icon: "building",
      },
      {
        no: "03",
        title: "Marketplace lite",
        copy: "Đưa người bán thứ ba vào sớm; nâng lên gói marketplace đầy đủ khi cần.",
        points: ["Catalog và hiển thị theo vendor", "Chốt hoa hồng theo đơn", "Sổ payout và export ngân hàng"],
        icon: "store",
      },
    ],
  },
  capabilities: {
    eyebrow: "Có sẵn trong lõi",
    title: "Đủ sâu để không phải ghép cả chục plugin.",
    lead:
      "Lõi đã bao gồm những phần thường phải mua riêng: giỏ hàng, thuế, subscription, khuyến mãi và báo cáo vận hành.",
    items: [
      { icon: "box", title: "Catalog và media", copy: "Biến thể, tồn kho đa kho, ảnh WebP/AVIF và URL media sẵn sàng cho CDN.", tag: "Có sẵn" },
      { icon: "card", title: "Checkout và thanh toán", copy: "COD, chuyển khoản, VNPay, MoMo, Stripe, PayPal. Kích hoạt bằng credentials của bạn.", tag: "Có sẵn" },
      { icon: "truck", title: "Vận chuyển và nhận hàng", copy: "GHN, Viettel Post, DHL; click & collect với điểm nhận hàng.", tag: "Có sẵn" },
      { icon: "tag", title: "Giá, thuế và khuyến mãi", copy: "Price list B2B, tax zone, voucher, gift card, store credit và member deal.", tag: "Có sẵn" },
      { icon: "repeat", title: "Subscription", copy: "Gói định kỳ, đơn lặp lại, xử lý gia hạn và cổng quản lý cho người đăng ký.", tag: "Có sẵn" },
      { icon: "store", title: "Marketplace lite", copy: "Vendor, chốt hoa hồng và sổ payout kèm export CSV ngân hàng.", tag: "Lite" },
      { icon: "chart", title: "Vận hành", copy: "Health check, metrics Prometheus, diễn tập backup, audit trail và consent.", tag: "Có sẵn" },
      { icon: "code", title: "Headless API", copy: "REST và OpenAPI, webhook, storefront và admin tách rời.", tag: "Có sẵn" },
    ],
  },
  industries: {
    eyebrow: "Giao diện khởi đầu sát nhu cầu",
    title: "18 preset ngành, rồi biến thành của bạn.",
    lead:
      "Áp preset trong admin, sau đó chỉnh màu, font, header, footer, kiểu card và section trang chủ. Không cần chợ theme.",
    note: "Preset là điểm khởi đầu, không khóa template. Mỗi store có thể clone preset, giữ theme key riêng và đổi bố cục theo channel.",
    items: [
      "Thời trang",
      "Beauty",
      "Nhà thuốc",
      "F&B",
      "Điện tử",
      "B2B",
      "Siêu thị",
      "Mỹ phẩm",
      "Giày dép",
      "Trang sức",
      "Nội thất",
      "Thể thao",
      "Thú cưng",
      "Mẹ & bé",
      "Sách",
      "Hoa & quà",
      "Thực phẩm bổ sung",
      "Phụ tùng xe",
    ],
  },
  operations: {
    eyebrow: "Dành cho người vận hành",
    title: "Vận hành như hạ tầng, không phải hộp đen.",
    lead:
      "Bạn có endpoint, job và bản ghi cần thiết để chạy commerce sau khi launch: health, metrics, backup, audit và consent.",
    points: [
      "Database và backup của bạn",
      "Endpoint health và readiness",
      "Job chạy qua queue, có retry",
      "Audit trail và kiểm soát riêng tư",
    ],
    panelTitle: "Bề mặt vận hành",
    panelTag: "Có sẵn",
    panelRows: [
      { label: "Health và readiness API", value: "Có endpoint" },
      { label: "Job nền", value: "Queue-backed" },
      { label: "Backup và restore", value: "Có runbook" },
      { label: "Metrics", value: "Prometheus" },
      { label: "Audit và consent", value: "Tích hợp sẵn" },
    ],
  },
  editions: {
    eyebrow: "Open core",
    title: "Bắt đầu miễn phí. Nâng cấp khi doanh nghiệp cần.",
    lead:
      "Bản Community là toàn bộ lõi commerce, self-host. Bản trả phí là đóng gói, license và hỗ trợ — không phải một codebase khác.",
    community: {
      name: "Community",
      badge: "Miễn phí",
      price: "Self-host",
      copy: "Dành cho đội muốn tự chủ hạ tầng và có năng lực kỹ thuật để vận hành.",
      points: ["Toàn bộ lõi commerce", "18 preset ngành", "Mã nguồn API và storefront", "Nâng cấp theo nhịp của bạn"],
      cta: "Nhận tư vấn",
    },
    pro: {
      name: "Pro",
      badge: "Đang hoàn thiện",
      price: "Theo license",
      copy: "Dành cho đơn vị nhiều store cần đóng gói theme, quản trị và lựa chọn managed.",
      points: ["Multi-store theming đóng gói", "License và entitlement", "SSO, audit export, AI managed", "Hỗ trợ ưu tiên và SLA"],
      cta: "Nhận cập nhật Pro",
    },
    note: "Pro chưa mở bán. Phạm vi và giá cuối cùng sẽ công bố khi phát hành; các mục trên không được bán như tính năng Pro đã có hôm nay.",
  },
  steps: {
    eyebrow: "Bắt đầu",
    title: "Từ buổi trao đổi đầu tiên đến cửa hàng vận hành được.",
    lead: "Quy trình ngắn, thiên về kỹ thuật. Không có bất ngờ migration giấu trong slide bán hàng.",
    items: [
      { no: "01", title: "Xác định phạm vi", copy: "Mô hình kinh doanh, quy mô catalog, provider và ngày launch thực tế." },
      { no: "02", title: "Triển khai", copy: "Cài trên hạ tầng của bạn, kết nối credentials thanh toán/vận chuyển, migrate dữ liệu." },
      { no: "03", title: "Vận hành", copy: "Đào tạo đội ngũ, bàn giao runbook và quyết định có cần hỗ trợ dài hạn." },
    ],
  },
  faq: {
    eyebrow: "Hỏi đáp",
    title: "Những câu đội ngũ thường hỏi trước khi bắt đầu.",
    lead: "Câu trả lời ngắn cho cả bên kỹ thuật và kinh doanh.",
    items: [
      {
        question: "SiroCommerce có miễn phí không?",
        answer:
          "Bản Community miễn phí để self-host và gồm toàn bộ lõi commerce. Pro và Enterprise đang hoàn thiện, sẽ bán dưới dạng tính năng đóng gói, license và hỗ trợ — không phải fork riêng.",
      },
      {
        question: "Có bắt buộc chạy trên cloud của SiroCommerce không?",
        answer:
          "Không. SiroCommerce cài trên hạ tầng bạn kiểm soát. Bạn cung cấp application server, database, cache và object storage; trang marketing này là static nên có thể chạy trên CDN.",
      },
      {
        question: "Có hỗ trợ thanh toán và vận chuyển Việt Nam không?",
        answer:
          "Có. Tích hợp VNPay, MoMo, GHN và Viettel Post nằm trong mã nguồn và được kích hoạt bằng merchant credentials của bạn. Lựa chọn quốc tế gồm Stripe, PayPal và DHL.",
      },
      {
        question: "Đây có phải sản phẩm SaaS không?",
        answer:
          "Không. Đây là phần mềm self-host. Đội của bạn sở hữu deployment, dữ liệu và việc nâng cấp. Bản trả phí bổ sung license, tính năng đóng gói và hỗ trợ trên cùng codebase.",
      },
      {
        question: "Có chạy D2C và B2B trên cùng catalog được không?",
        answer:
          "Được. Công ty, price list, quote và phê duyệt nằm cạnh checkout bán lẻ. Store và channel scope tách catalog, giá và theme khi cần.",
      },
      {
        question: "Bản Pro có gì?",
        answer:
          "Dự kiến: multi-store theming đóng gói, license và entitlement, SSO/SAML, audit export, lựa chọn AI managed, hỗ trợ ưu tiên và SLA. Chưa mở bán; phạm vi cuối cùng sẽ công bố trước khi bán.",
      },
    ],
  },
  contact: {
    eyebrow: "Liên hệ",
    title: "Kể cho chúng tôi bạn đang xây gì.",
    lead:
      "Gửi mô hình kinh doanh, quy mô catalog ước tính và các provider bạn cần. Chúng tôi sẽ trả lời qua email với các câu hỏi kỹ thuật tiếp theo.",
    button: "Gửi email cho đội ngũ",
    note: "Muốn trao đổi nhanh? Ghi kèm múi giờ, chúng tôi sẽ đề xuất khung giờ.",
  },
  footer: {
    tagline: "Thương mại điện tử self-host cho đội ngũ muốn tự chủ hạ tầng.",
    columns: [
      {
        title: "Nền tảng",
        links: [
          { label: "Tổng quan", href: "#platform" },
          { label: "Năng lực", href: "#capabilities" },
          { label: "Ngành", href: "#industries" },
          { label: "Vận hành", href: "#operations" },
        ],
      },
      {
        title: "Phiên bản",
        links: [
          { label: "Community", href: "#editions" },
          { label: "Pro (đang hoàn thiện)", href: "#editions" },
          { label: "Hỏi đáp", href: "#faq" },
        ],
      },
      {
        title: "Liên hệ",
        links: [
          { label: "Email", href: "#contact" },
          { label: "Nhận tư vấn", href: "#contact" },
        ],
      },
    ],
    rights: "© 2026 SiroCommerce. Bảo lưu mọi quyền.",
    madeIn: "Thương mại điện tử self-host, làm tại Việt Nam.",
  },
};
