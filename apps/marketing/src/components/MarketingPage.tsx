import type { ReactNode } from "react";
import { contactEmail } from "@/lib/site";
import { locales, type Content, type IconName, type Locale } from "@/content";
import { localePath } from "@/content";

function Icon({ name, size = 22 }: { name: IconName; size?: number }) {
  const paths: Record<IconName, ReactNode> = {
    bag: <><path d="M5 8h14l-1.2 11H6.2L5 8Z" /><path d="M9 8V6.5a3 3 0 0 1 6 0V8" /></>,
    building: <><path d="M4 21h16" /><path d="M6 21V5h9v16" /><path d="M15 10h3v11" /><path d="M9 9h3M9 13h3M9 17h3" /></>,
    store: <><path d="M4 10v10h16V10" /><path d="M3 10l2-6h14l2 6" /><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0" /><path d="M9 20v-5h6v5" /></>,
    box: <><path d="M4 7l8-4 8 4v10l-8 4-8-4V7Z" /><path d="M4 7l8 4 8-4M12 11v10" /></>,
    card: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></>,
    truck: <><path d="M3 6h11v10H3zM14 10h4l3 3v3h-7" /><circle cx="7" cy="18" r="2" /><circle cx="17" cy="18" r="2" /></>,
    tag: <><path d="M4 4h7l9 9-7 7-9-9V4Z" /><circle cx="8.5" cy="8.5" r="1.5" /></>,
    repeat: <><path d="M4 9a5 5 0 0 1 5-5h9l-3-3M20 15a5 5 0 0 1-5 5H6l3 3" /></>,
    chart: <><path d="M4 20V4M4 20h16" /><path d="M8 16v-5M12 16V7M16 16v-8M20 16v-3" /></>,
    code: <><path d="M9 7l-5 5 5 5M15 7l5 5-5 5" /></>,
    shield: <><path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6l7-3Z" /><path d="M9 12l2 2 4-4" /></>,
    check: <path d="M4 12l5 5L20 6" />,
  };
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false">
      {paths[name]}
    </svg>
  );
}

function SectionHead({ eyebrow, title, lead }: { eyebrow: string; title: string; lead: string }) {
  return (
    <div className="section-head">
      <p className="eyebrow">{eyebrow}</p>
      <h2>{title}</h2>
      <p className="lead">{lead}</p>
    </div>
  );
}

export function MarketingPage({ locale, content }: { locale: Locale; content: Content }) {
  const mailto = (subject: string) => `mailto:${contactEmail}?subject=${encodeURIComponent(subject)}`;
  const href = (anchor: string) => `${localePath(locale)}${anchor}`;

  return (
    <>
      <header className="site-header">
        <div className="wrap header-row">
          <a className="brand" href={localePath(locale)} aria-label="SiroCommerce">
            <span className="brand-mark">siro</span>
            <span className="brand-slash" aria-hidden="true">/</span>
            <span className="brand-word">commerce</span>
          </a>

          <nav className="desk-nav" aria-label={locale === "vi" ? "Điều hướng chính" : "Primary"}>
            {content.nav.map((item) => (
              <a key={item.href} href={href(item.href)}>{item.label}</a>
            ))}
          </nav>

          <div className="header-actions">
            <div className="lang-switch" role="group" aria-label={content.ui.language}>
              {locales.map((code) => (
                <a key={code} href={localePath(code)} hrefLang={code} lang={code} aria-current={code === locale ? "true" : undefined}>
                  {code.toUpperCase()}
                </a>
              ))}
            </div>
            <a className="btn btn-solid btn-sm desk-cta" href={mailto(content.cta.emailSubject)}>{content.cta.talk}</a>
            <details className="mobile-menu">
              <summary>{content.ui.menu}</summary>
              <div className="mobile-panel">
                <nav aria-label={content.ui.menu}>
                  {content.nav.map((item) => (
                    <a key={item.href} href={href(item.href)}>{item.label}</a>
                  ))}
                  <a href={`mailto:${contactEmail}?subject=${encodeURIComponent(content.cta.emailSubject)}`}>{content.cta.talk}</a>
                </nav>
                <div className="mobile-langs">
                  <span>{content.ui.language}</span>
                  <div>
                    {locales.map((code) => (
                      <a key={code} href={localePath(code)} hrefLang={code} lang={code} aria-current={code === locale ? "true" : undefined}>
                        {code.toUpperCase()}
                      </a>
                    ))}
                  </div>
                </div>
              </div>
            </details>
          </div>
        </div>
      </header>

      <main id="main">
        <section className="hero">
          <div className="wrap hero-grid">
            <div className="hero-copy">
              <p className="eyebrow">{content.hero.eyebrow}</p>
              <h1>{content.hero.title}</h1>
              <p className="lead">{content.hero.lead}</p>
              <div className="hero-actions">
                <a className="btn btn-solid" href={mailto(content.cta.emailSubject)}>{content.cta.talk}</a>
                <a className="btn btn-ghost" href={href("#capabilities")}>{content.cta.features} <span aria-hidden="true">{content.ui.arrow}</span></a>
              </div>
              <ul className="hero-notes">
                {content.hero.notes.map((note) => (
                  <li key={note}><Icon name="check" size={16} />{note}</li>
                ))}
              </ul>
            </div>

            <div className="hero-visual" role="img" aria-label={content.hero.visual.label}>
              <div className="store-mock" aria-hidden="true">
                <div className="mock-bar">
                  <span className="mock-dots"><i /><i /><i /></span>
                  <span className="mock-url">{content.hero.visual.windowTitle}</span>
                </div>
                <div className="mock-body">
                  <div className="mock-main">
                    <div className="mock-top">
                      <strong>{content.hero.visual.storeName}</strong>
                      <span className="mock-nav">{content.hero.visual.nav.map((item) => <em key={item}>{item}</em>)}</span>
                    </div>
                    <div className="mock-hero">
                      <div>
                        <h3>{content.hero.visual.heroTitle}</h3>
                        <p>{content.hero.visual.heroCopy}</p>
                        <span className="mock-btn">{content.hero.visual.checkout}</span>
                      </div>
                      <div className="mock-vials">
                        <span className="vial vial-a" /><span className="vial vial-b" /><span className="vial vial-c" />
                      </div>
                    </div>
                    <div className="mock-products">
                      {content.hero.visual.products.map((product) => (
                        <div className="mock-card" key={product.name}>
                          <span className={`mock-art tone-${product.tone}`} />
                          <span className="mock-line">{product.name}</span>
                          <span className="mock-price">{product.price}</span>
                        </div>
                      ))}
                    </div>
                    <p className="mock-foot">{content.hero.visual.foot}</p>
                  </div>
                  <aside className="mock-cart">
                    <strong>{content.hero.visual.cartTitle}</strong>
                    {content.hero.visual.cartLines.map((line) => (
                      <div className="mock-cart-line" key={line.name}>
                        <span>{line.name}<em>{line.meta}</em></span>
                        <b>{line.price}</b>
                      </div>
                    ))}
                    <div className="mock-total"><span>{content.hero.visual.cartTitle}</span><b>{content.hero.visual.cartTotal}</b></div>
                    <span className="mock-btn mock-btn-full">{content.hero.visual.checkout}</span>
                    <p className="mock-cart-note">{content.hero.visual.cartNote}</p>
                  </aside>
                </div>
              </div>
            </div>
          </div>

          <div className="wrap proof-strip">
            {content.proof.map((item) => (
              <div className="proof-item" key={item.label}>
                <strong>{item.value}</strong>
                <span>{item.label}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="section" id="platform">
          <div className="wrap">
            <SectionHead eyebrow={content.platform.eyebrow} title={content.platform.title} lead={content.platform.lead} />
            <div className="cards-3">
              {content.platform.cards.map((card) => (
                <article className="card platform-card" key={card.no}>
                  <div className="card-top">
                    <span className="icon-box"><Icon name={card.icon} /></span>
                    <span className="card-no">{card.no}</span>
                  </div>
                  <h3>{card.title}</h3>
                  <p>{card.copy}</p>
                  <ul className="check-list">
                    {card.points.map((point) => <li key={point}><Icon name="check" size={16} />{point}</li>)}
                  </ul>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="section section-alt" id="capabilities">
          <div className="wrap">
            <SectionHead eyebrow={content.capabilities.eyebrow} title={content.capabilities.title} lead={content.capabilities.lead} />
            <div className="cards-4">
              {content.capabilities.items.map((item) => (
                <article className="card capability-card" key={item.title}>
                  <div className="card-top">
                    <span className="icon-box"><Icon name={item.icon} /></span>
                    <span className="tag">{item.tag}</span>
                  </div>
                  <h3>{item.title}</h3>
                  <p>{item.copy}</p>
                </article>
              ))}
            </div>
          </div>
        </section>

        <section className="section" id="industries">
          <div className="wrap">
            <SectionHead eyebrow={content.industries.eyebrow} title={content.industries.title} lead={content.industries.lead} />
            <ul className="industry-list">
              {content.industries.items.map((item, index) => (
                <li key={item}>
                  <span className="industry-no">{String(index + 1).padStart(2, "0")}</span>
                  {item}
                </li>
              ))}
            </ul>
            <p className="note-box">{content.industries.note}</p>
          </div>
        </section>

        <section className="section section-alt" id="operations">
          <div className="wrap split">
            <div>
              <SectionHead eyebrow={content.operations.eyebrow} title={content.operations.title} lead={content.operations.lead} />
              <ul className="check-list check-list-lg">
                {content.operations.points.map((point) => <li key={point}><Icon name="shield" size={18} />{point}</li>)}
              </ul>
            </div>
            <div className="ops-panel">
              <div className="ops-head"><span>{content.operations.panelTitle}</span><span className="tag">{content.operations.panelTag}</span></div>
              {content.operations.panelRows.map((row) => (
                <div className="ops-row" key={row.label}>
                  <span>{row.label}</span>
                  <b>{row.value}</b>
                </div>
              ))}
            </div>
          </div>
        </section>

        <section className="section" id="editions">
          <div className="wrap">
            <SectionHead eyebrow={content.editions.eyebrow} title={content.editions.title} lead={content.editions.lead} />
            <div className="edition-grid">
              <article className="card edition-card">
                <div className="card-top">
                  <h3>{content.editions.community.name}</h3>
                  <span className="tag tag-strong">{content.editions.community.badge}</span>
                </div>
                <p className="edition-price">{content.editions.community.price}</p>
                <p>{content.editions.community.copy}</p>
                <ul className="check-list">
                  {content.editions.community.points.map((point) => <li key={point}><Icon name="check" size={16} />{point}</li>)}
                </ul>
                <a className="btn btn-solid btn-block" href={mailto(content.cta.emailSubject)}>{content.editions.community.cta}</a>
              </article>
              <article className="card edition-card edition-pro">
                <div className="card-top">
                  <h3>{content.editions.pro.name}</h3>
                  <span className="tag">{content.editions.pro.badge}</span>
                </div>
                <p className="edition-price">{content.editions.pro.price}</p>
                <p>{content.editions.pro.copy}</p>
                <ul className="check-list">
                  {content.editions.pro.points.map((point) => <li key={point}><Icon name="check" size={16} />{point}</li>)}
                </ul>
                <a className="btn btn-outline btn-block" href={mailto(content.cta.waitlistSubject)}>{content.editions.pro.cta}</a>
              </article>
            </div>
            <p className="note-box">{content.editions.note}</p>
          </div>
        </section>

        <section className="section section-alt" id="steps">
          <div className="wrap">
            <SectionHead eyebrow={content.steps.eyebrow} title={content.steps.title} lead={content.steps.lead} />
            <ol className="steps">
              {content.steps.items.map((step) => (
                <li key={step.no}>
                  <span className="step-no">{step.no}</span>
                  <h3>{step.title}</h3>
                  <p>{step.copy}</p>
                </li>
              ))}
            </ol>
          </div>
        </section>

        <section className="section" id="faq">
          <div className="wrap narrow">
            <SectionHead eyebrow={content.faq.eyebrow} title={content.faq.title} lead={content.faq.lead} />
            <div className="faq">
              {content.faq.items.map((item) => (
                <details key={item.question}>
                  <summary>{item.question}<span aria-hidden="true">+</span></summary>
                  <p>{item.answer}</p>
                </details>
              ))}
            </div>
          </div>
        </section>

        <section className="section contact-section" id="contact">
          <div className="wrap">
            <div className="contact-card">
              <p className="eyebrow">{content.contact.eyebrow}</p>
              <h2>{content.contact.title}</h2>
              <p className="lead">{content.contact.lead}</p>
              <div className="hero-actions">
                <a className="btn btn-solid" href={mailto(content.cta.emailSubject)}>{content.contact.button}</a>
                <a className="btn btn-ghost" href={`mailto:${contactEmail}`}>{contactEmail}</a>
              </div>
              <p className="muted">{content.contact.note}</p>
            </div>
          </div>
        </section>
      </main>

      <footer className="site-footer">
        <div className="wrap footer-grid">
          <div className="footer-brand">
            <a className="brand" href={localePath(locale)} aria-label="SiroCommerce">
              <span className="brand-mark">siro</span><span className="brand-slash" aria-hidden="true">/</span><span className="brand-word">commerce</span>
            </a>
            <p>{content.footer.tagline}</p>
            <p className="muted">{contactEmail}</p>
          </div>
          {content.footer.columns.map((column) => (
            <nav key={column.title} aria-label={column.title}>
              <h3>{column.title}</h3>
              {column.links.map((link) => (
                <a key={link.label} href={link.href === "#contact" ? mailto(content.cta.emailSubject) : href(link.href)}>{link.label}</a>
              ))}
            </nav>
          ))}
        </div>
        <div className="wrap footer-bottom">
          <span>{content.footer.rights}</span>
          <span>{content.footer.madeIn}</span>
        </div>
      </footer>
    </>
  );
}
