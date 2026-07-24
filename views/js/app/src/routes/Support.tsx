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
          <div className="font-semibold text-[16px] mb-1">{t('support.coffee_title', 'Buy me a coffee')}</div>
          <p className="text-[13px] text-slate-700 mb-4 leading-relaxed">
            {t('support.coffee_text', 'If SmartBulk saves you time, consider supporting development. Every coffee buys another evening of feature work.')}
          </p>
          <a href={links.buy_a_coffee} target="_blank" rel="noreferrer noopener">
            <Button variant="primary" className="bg-amber-500 border-amber-500 hover:bg-amber-600">
              {t('support.coffee_btn', '☕ Support on Buy Me a Coffee')}
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

      <div className="mt-6 text-[12px] text-muted-foreground">
        {t2('support.footer', 'SmartBulk v{v} · PrestaShop 8 & 9 · PHP 8.1+', { v: window.SmartBulk.module.version })}
      </div>
    </PageCard>
  );
}
