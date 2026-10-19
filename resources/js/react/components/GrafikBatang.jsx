import { Bar } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Tooltip,
    Legend,
    Title,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend, Title);

const PALET = ['#005EB8', '#159447', '#EB1C24', '#F5A623', '#003D7D', '#90A4AE'];

export default function GrafikBatang({ data = [], label = 'Jumlah', judul = '', sumbuX = 'label', sumbuY = 'jumlah' }) {
    const labels = data.map((d) => d[sumbuX] ?? d.label ?? d.tanggal ?? '-');
    const values = data.map((d) => Number(d[sumbuY] ?? d.jumlah ?? d.total ?? 0));

    const chartData = {
        labels,
        datasets: [
            {
                label,
                data: values,
                backgroundColor: PALET[0],
                borderRadius: 4,
            },
        ],
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false,
            },
            title: {
                display: Boolean(judul),
                text: judul,
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0,
                },
            },
        },
    };

    return (
        <div style={{ height: 260 }}>
            <Bar data={chartData} options={options} />
        </div>
    );
}
