import PageHead from '../components/PageHead';
import PageCard from '../components/PageCard';
import Button from '../components/Button';
import { t, t2 } from '../lib/i18n';

export default function Support() {
  const links = window.SmartBulk.links;

  return (
    <PageCard>
      <PageHead
        title={t('support.page_title', 'Support SmartBulk')}
        subtitle={t('support.page_subtitle', 'Free and open source — by marcingajewski.pl')}
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="border border-amber-200 bg-amber-50 rounded-lg p-6">
          <div className="text-3xl mb-2">☕</div>
          <div className="font-semibold text-[16px] mb-1">{t('support.coffee_title', 'Support on Ko-fi')}</div>
          <p className="text-[13px] text-slate-700 mb-4 leading-relaxed">
            {t('support.coffee_text', 'If SmartBulk saves you time, consider supporting development. Every coffee buys another evening of feature work.')}
          </p>
          <a href={links.kofi} target="_blank" rel="noreferrer noopener">
            <Button variant="primary" className="bg-amber-500 border-amber-500 hover:bg-amber-600">
              {t('support.coffee_btn', '☕ Support on Ko-fi')}
            </Button>
          </a>
        </div>

        <div className="border border-border bg-white rounded-lg p-6">
          <div className="text-3xl mb-2">🐙</div>
          <div className="font-semibold text-[16px] mb-1">{t('support.github_title', 'GitHub repository')}</div>
          <p className="text-[13px] text-slate-700 mb-4 leading-relaxed">
            {t('support.github_text', 'Report issues, request features, or contribute. The module is open source under the AFL-3.0 license.')}
          </p>
          <a href={links.github} target="_blank" rel="noreferrer noopener">
            <Button>{t('support.github_btn', 'Open on GitHub →')}</Button>
          </a>
        </div>

        <div className="border border-border bg-white rounded-lg p-6">
          <div className="text-3xl mb-2">📚</div>
          <div className="font-semibold text-[16px] mb-1">{t('support.docs_title', 'Documentation')}</div>
          <p className="text-[13px] text-slate-700 mb-4 leading-relaxed">
            {t('support.docs_text', 'Installation guide, feature overview, roadmap, and architecture docs are in the repository README.')}
          </p>
          <a href={`${links.github}#readme`} target="_blank" rel="noreferrer noopener">
            <Button>{t('support.docs_btn', 'Read the docs →')}</Button>
          </a>
        </div>
      </div>

      {/* Quince Project: the author's theme and module suite. Styles: views/css/quince-promo.css */}
      <section className="quince-promo">
        <div className="quince-promo-wash" aria-hidden="true" />
        <i className="quince-promo-beam b1" aria-hidden="true" />
        <i className="quince-promo-beam b2" aria-hidden="true" />
        <i className="quince-promo-beam b3" aria-hidden="true" />
        <i className="quince-promo-beam b4" aria-hidden="true" />
        <div className="quince-promo-in">
          <p className="quince-promo-lead">{t('support.quince_lead', 'Discover the theme and engine for PrestaShop')}</p>
          <p className="quince-promo-word">Quince Project</p>
          <p className="quince-promo-text">
            {t('support.quince_text', 'A set of over 20 modules and a theme that let you freely manage the look and content of your shop and support back-office processes.')}
          </p>
          <div className="quince-promo-cta">
            <a className="quince-promo-btn" href={links.quince} target="_blank" rel="noreferrer noopener">
              <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z" /></svg>
              {t('support.quince_btn', 'Download for free from GitHub')}
            </a>
          </div>
          <div className="quince-promo-facts">
            <div><b>PrestaShop 9.2</b></div>
            <div><b>{t('support.quince_php', 'PHP 8.1 or newer')}</b></div>
            <div><b>{t('support.quince_licence', 'OSL-3.0 licence')}</b></div>
          </div>
        </div>
      </section>

      <div className="mt-6 text-[12px] text-muted-foreground">
        {t2('support.footer', 'SmartBulk v{v} · PrestaShop 8 & 9 · PHP 8.1+', { v: window.SmartBulk.module.version })}
      </div>
    </PageCard>
  );
}
