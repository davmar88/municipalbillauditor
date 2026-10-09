import { NotFound } from '@/components/NotFound';
import { useIdParam } from '@/hooks/useIdParam';
import { AddBillScreen } from '@/screens/AddBillScreen';

export default function AddBillRoute() {
  const id = useIdParam();
  if (!id) return <NotFound />;
  return <AddBillScreen key={id} propertyId={id} />;
}
