import { NotFound } from '@/components/NotFound';
import { useIdParam } from '@/hooks/useIdParam';
import { PropertyFormScreen } from '@/screens/PropertyFormScreen';

export default function EditPropertyRoute() {
  const id = useIdParam();
  if (!id) return <NotFound />;
  return <PropertyFormScreen key={id} propertyId={id} />;
}
