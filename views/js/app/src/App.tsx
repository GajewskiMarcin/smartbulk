import { useEffect } from 'react';
import { Routes, Route, Navigate, useLocation, useNavigate } from 'react-router-dom';
import Nav from './components/Nav';
import Dashboard from './routes/Dashboard';
import BulkEditor from './routes/BulkEditor';
import AIAssistant from './routes/AIAssistant';
import Prompts from './routes/Prompts';
import Health from './routes/Health';
import History from './routes/History';
import Scheduler from './routes/Scheduler';
import Settings from './routes/Settings';
import Support from './routes/Support';

export default function App() {
  return (
    <div className="flex flex-col gap-3">
      <Nav />
      <GridHandoffRouter />
      <AiHandoffRouter />
      <Routes>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/bulk-editor" element={<BulkEditor />} />
        <Route path="/ai" element={<AIAssistant />} />
        <Route path="/prompts" element={<Prompts />} />
        <Route path="/health" element={<Health />} />
        <Route path="/history" element={<History />} />
        <Route path="/scheduler" element={<Scheduler />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="/support" element={<Support />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </div>
  );
}

/**
 * One-shot router-level effect: when the bootstrap carries an ai_handoff
 * (user arrived from the per-issue "Fix with AI" button on the product
 * form's Health panel), auto-navigate to /ai with productId + taskType
 * as router state. AIAssistant prefills its UI from that state.
 */
function AiHandoffRouter() {
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const h = window.SmartBulk?.ai_handoff;
    if (!h || !h.product_id) return;
    if (location.pathname.startsWith('/ai')) return;
    delete window.SmartBulk.ai_handoff;
    navigate('/ai', {
      state: {
        ai_handoff: {
          productId: h.product_id,
          taskType:  h.task_type,
        },
      },
    });
  }, [navigate, location.pathname]);

  return null;
}

/**
 * One-shot router-level effect: when the bootstrap carries a grid_handoff
 * (user arrived from the native PS product-grid bulk action), auto-navigate
 * to /bulk-editor with the productIds packaged as router state — same shape
 * BulkEditor already consumes from Content Health handoff.
 */
function GridHandoffRouter() {
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const h = window.SmartBulk?.grid_handoff;
    if (!h || !Array.isArray(h.product_ids) || h.product_ids.length === 0) return;
    if (location.pathname.startsWith('/bulk-editor')) return; // already there
    // Consume — second mount must not re-trigger.
    delete window.SmartBulk.grid_handoff;
    navigate('/bulk-editor', {
      state: {
        handoff: {
          productIds: h.product_ids,
          label: `Selected from PrestaShop product list (${h.product_ids.length.toLocaleString()})`,
          source: 'product_grid',
        },
      },
    });
  }, [navigate, location.pathname]);

  return null;
}
