import type { ReactNode, HTMLAttributes } from 'react';
import Card from './Card';

interface PageCardProps extends HTMLAttributes<HTMLDivElement> {
  children: ReactNode;
}

/**
 * Outer content surface for a view — wraps Card with xl padding (28px) so each
 * route gets consistent "page" chrome matching the prototypes' .module-content.
 */
export default function PageCard({ children, ...rest }: PageCardProps) {
  return (
    <Card padding="xl" {...rest}>
      {children}
    </Card>
  );
}
