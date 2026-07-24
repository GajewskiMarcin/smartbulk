interface ComingSoonProps {
  feature: string;
  version: string;
}

export default function ComingSoon({ feature, version }: ComingSoonProps) {
  return (
    <div className="border-2 border-dashed border-border rounded-lg p-12 text-center">
      <div className="text-4xl mb-3">🚧</div>
      <div className="text-lg font-semibold mb-1">{feature} — coming in {version}</div>
      <div className="text-muted-foreground text-[13px] max-w-md mx-auto">
        Full feature implementation queued after the MVP skeleton is validated.
      </div>
    </div>
  );
}
