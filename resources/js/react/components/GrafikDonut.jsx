import { Doughnut } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend,
    Title,
} from 'chart.js';

ChartJS.register(ArcElement, Tooltip, Legend, Title);

const PALET = ['#005EB8', '#159447', '#F5A623', '#EB1C24', '#003D7D', '#90A4AE', '#7C4DFF', '#FF7043'];

export default function GrafikDonut({ data = [], label = 'Jumlah', judul = '' }) {
    const labels = data.map((d) => d.label ?? d.urgensi ?? d.status ?? d.nama ?? '-');
    const values = data.map((d) => Number(d.jumlah ?? d.total ?? 0));

    const chartData = {
        labels,
        datasets: [
            {
                label,
                data: values,
                backgroundColor: PALET,
                borderWidth: 0,
            },
        ],
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            },
            title: {
                display: Boolean(judul),
                text: judul,
            },
        },
    };

    return (
        <div style={{ height: 260 }}>
            <Doughnut data={chartData} options={options} />
        </div>
    );
}
