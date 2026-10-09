import { NotFound } from '@/components/NotFound';
import { useIdParam } from '@/hooks/useIdParam';
import { DisputeDetailScreen } from '@/screens/DisputeDetailScreen';

export default function DisputeRoute() {
  const id = useIdParam();
  if (!id) return <NotFound />;
  return <DisputeDetailScreen key={id} disputeId={id} />;
}
