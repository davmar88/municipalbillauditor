import { NotFound } from '@/components/NotFound';
import { useIdParam } from '@/hooks/useIdParam';
import { BillDetailScreen } from '@/screens/BillDetailScreen';

export default function BillRoute() {
  const id = useIdParam();
  if (!id) return <NotFound />;
  return <BillDetailScreen key={id} billId={id} />;
}
