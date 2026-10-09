import { NotFound } from '@/components/NotFound';
import { useIdParam } from '@/hooks/useIdParam';
import { PropertyDetailScreen } from '@/screens/PropertyDetailScreen';

export default function PropertyRoute() {
  const id = useIdParam();
  if (!id) return <NotFound />;
  return <PropertyDetailScreen key={id} propertyId={id} />;
}
